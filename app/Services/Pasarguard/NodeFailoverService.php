<?php

namespace App\Services\Pasarguard;

use App\Jobs\ReplaceServerPollJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ServerProvisioningService;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Runs once, right when CheckServerPingJob first marks a server as failing
 * (see ping_alerted), for any server that has a DNS-backed WireGuard
 * profile — keeps that profile's users connected in two steps:
 *
 * 1. failoverDns(): instantly repoints the profile's domain at another
 *    currently-healthy server owned by the same admin — a temporary
 *    borrow. That server keeps serving its own profile too; the domain
 *    just gets pointed at the same IP as a second name. Best-effort: if no
 *    healthy sibling exists, this is a no-op (nothing to borrow).
 * 2. rebuildReplacement(): kicks off a real replacement build for the down
 *    server via the exact same pipeline as a manual "🔄 تغییر سرور" tap
 *    (ServerProvisioningService + ReplaceServerPollJob). Once THAT
 *    replacement's ping comes back clean, ReplaceServerFinishJob already
 *    re-points the domain back to its own dedicated IP on its own —
 *    nothing extra needed here to end the borrow.
 */
class NodeFailoverService
{
    public function handle(ServerSecret $downSecret, Nutgram $bot, int $ownerId): void
    {
        $profile = $downSecret->wireguardProfile;

        // Scoped to DNS-backed nodes only: a plain server with no WireGuard
        // profile was never part of this HA scheme, and auto-spinning up a
        // brand-new billable server for it without a human confirming first
        // (unlike every other create action in this bot) isn't something to
        // do silently on a possibly-flaky ping result.
        if (! $profile) {
            return;
        }

        $this->failoverDns($downSecret, $profile->name, $bot, $ownerId);
        $this->rebuildReplacement($downSecret, $ownerId, $bot);
    }

    protected function failoverDns(ServerSecret $downSecret, string $profileName, Nutgram $bot, int $ownerId): void
    {
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

        $dns = (new PasarguardNodeInstaller())->syncProfileDns($profileName, $ip);

        if ($dns && ! $dns['error']) {
            $bot->sendMessage(
                "🔀 دامنه‌ی پروفایل «{$profileName}» موقتاً به سرور «{$candidate->hostname}» ({$ip}) سوییچ شد تا کاربران قطع نشوند؛ به‌محض آماده شدن سرور جایگزین، دامنه دوباره برمی‌گردد.",
                chat_id: $ownerId,
            );
        }
    }

    protected function rebuildReplacement(ServerSecret $downSecret, int $ownerId, Nutgram $bot): void
    {
        if (! $downSecret->region || ! $downSecret->size || ! $downSecret->image || ! $downSecret->hostname) {
            return; // no saved build spec (e.g. a pre-existing server) — nothing to rebuild from
        }

        $panel = Panel::find($downSecret->panel_id);

        if (! $panel) {
            return;
        }

        try {
            [$actionId] = app(ServerProvisioningService::class)->createSilently(
                $panel,
                $downSecret->hostname,
                $downSecret->region,
                $downSecret->size,
                $downSecret->image,
            );
        } catch (ProviderException $e) {
            $bot->sendMessage("❌ ساخت خودکار سرور جایگزین ناموفق بود:\n{$e->getMessage()}", chat_id: $ownerId);

            return;
        }

        if (! $actionId) {
            return;
        }

        ReplaceServerPollJob::dispatch(
            $downSecret->panel_id,
            (string) $downSecret->provider_server_id,
            $actionId,
            $downSecret->hostname,
            $downSecret->region,
            $downSecret->size,
            $downSecret->image,
            $downSecret->wireguard_profile_id !== null ? (int) $downSecret->wireguard_profile_id : null,
            $ownerId,
            1,
        );
    }
}
