<?php

namespace App\Services\Pasarguard;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\Providers\ProviderManager;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Runs once, right when CheckServerPingJob first marks a server as failing
 * (see ping_alerted), for any server that has a DNS-backed WireGuard
 * profile — instantly repoints that profile's domain (e.g.
 * "server3.node.pcbot.top") at another currently-healthy sibling server
 * owned by the same admin, so existing users don't get disconnected. That
 * sibling keeps serving its own profile's domain too; the down profile's
 * domain just gets pointed at the same IP as a second name. Best-effort: if
 * no healthy sibling exists, this is a no-op — nothing to borrow, and no
 * new server is created here (that stays a manual "🔄 تغییر سرور" tap).
 */
class NodeFailoverService
{
    public function handle(ServerSecret $downSecret, Nutgram $bot, int $ownerId): void
    {
        $profile = $downSecret->wireguardProfile;

        if (! $profile) {
            return;
        }

        $candidate = ServerSecret::whereHas('panel', fn ($q) => $q->where('created_by', $ownerId)->where('is_active', true))
            ->whereNotNull('wireguard_profile_id')
            ->where('ping_alerted', false)
            ->where('id', '!=', $downSecret->id)
            ->first();

        if (! $candidate) {
            return;
        }

        try {
            $panel = Panel::find($candidate->panel_id);
            $server = $panel ? ProviderManager::forPanel($panel)->getServer($candidate->provider_server_id) : null;
            $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null;
        } catch (Throwable) {
            return;
        }

        if (! $ip) {
            return;
        }

        $dns = (new PasarguardNodeInstaller())->syncProfileDns($profile->name, $ip);

        if ($dns && ! $dns['error']) {
            $bot->sendMessage(
                "🔀 دامنه‌ی پروفایل «{$profile->name}» موقتاً به سرور «{$candidate->hostname}» ({$ip}) سوییچ شد تا کاربران قطع نشوند.",
                chat_id: $ownerId,
            );
        }
    }
}
