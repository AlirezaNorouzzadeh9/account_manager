<?php

namespace App\Jobs;

use App\Models\ConnectedServer;
use App\Models\ServerSecret;
use App\Models\WireguardLocation;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Dns\DnsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Health check for one WireGuard location (see wireguard:check-locations,
 * run every 3 minutes): a location's "ip" is often just one of several IPs
 * a rotating "hostname" subdomain hands out, so unlike a genuinely dead
 * server, a bad ip here can usually be fixed by re-resolving the subdomain
 * for a fresh one — no admin action needed.
 *
 * Only runs for locations that HAVE a hostname set (WireguardMenu's
 * "🌐 تنظیم دامنه" is optional) — same "nothing to check yet" skip as
 * CheckWireguardProfileJob does for a profile with no own_ip.
 *
 * On a real failure that re-resolves to a NEW ip, the location is updated
 * and every one of this owner's WireGuard-enabled servers — both
 * panel-provisioned (ServerSecret) and manually-connected (ConnectedServer)
 * — gets pushed a fresh config via UpdateWireguardsJob/
 * ConnectServerWireguardsJob, so the fix actually reaches running servers
 * instead of just the database. Alerts once per ongoing problem via
 * WireguardLocation::ping_alerted, same pattern as
 * ServerSecret::ping_alerted/WireguardProfile::ping_alerted.
 */
class CheckWireguardLocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(protected int $locationId)
    {
    }

    public function handle(CheckHostClient $checkHost, DnsResolver $dns, Nutgram $bot): void
    {
        $location = WireguardLocation::find($this->locationId);

        if (! $location || ! $location->hostname) {
            return;
        }

        try {
            $requestId = $checkHost->requestPing($location->ip);
        } catch (Throwable) {
            return; // check-host itself being unreachable isn't a location problem
        }

        $result = null;

        for ($i = 0; $i < 10; $i++) {
            $result = $checkHost->getResult($requestId);

            if ($result !== null) {
                break;
            }

            sleep(3);
        }

        // No result at all (check-host hiccup) => nothing to report either way.
        if ($result === null) {
            return;
        }

        if ($checkHost->allNodesOk($result)) {
            if ($location->ping_alerted) {
                $location->update(['ping_alerted' => false]);
                $bot->sendMessage(
                    "✅ لوکیشن «{$location->name}» ({$location->ip}) دوباره از ایران در دسترس است.",
                    chat_id: $location->created_by,
                );
            }

            return;
        }

        $newIp = $dns->resolve($location->hostname);

        if ($newIp && $newIp !== $location->ip) {
            $oldIp = $location->ip;
            $location->update(['ip' => $newIp, 'ping_alerted' => false]);

            $bot->sendMessage(
                "🔁 آی‌پی لوکیشن «{$location->name}» چون از ایران در دسترس نبود، از {$oldIp} به {$newIp} تغییر کرد؛ در حال بروزرسانی سرورهای فعال...",
                chat_id: $location->created_by,
            );

            $this->pushToAffectedServers($location, $bot);

            return;
        }

        if (! $location->ping_alerted) {
            $location->update(['ping_alerted' => true]);
            $bot->sendMessage(
                "⚠️ لوکیشن «{$location->name}» ({$location->ip}) از ایران در دسترس نیست و ترمیم خودکار ممکن نشد ".
                "(دامنه‌اش «{$location->hostname}» یا resolve نشد یا هنوز همون IP قبلی را می‌دهد). دستی بررسی کنید.",
                chat_id: $location->created_by,
            );
        }
    }

    /**
     * Every WireGuard-enabled server of this location's owner gets a fresh
     * config pushed — panel-provisioned ones via UpdateWireguardsJob (looked
     * up through ServerSecret/Panel), manually-connected ones via
     * ConnectServerWireguardsJob (looked up through ConnectedServer, which
     * only exists because ConnectServerConversation now persists its
     * credentials).
     */
    protected function pushToAffectedServers(WireguardLocation $location, Nutgram $bot): void
    {
        $panelServers = ServerSecret::whereNotNull('wireguard_profile_id')
            ->whereHas('panel', fn ($query) => $query->where('created_by', $location->created_by))
            ->get();

        foreach ($panelServers as $secret) {
            UpdateWireguardsJob::dispatch($secret->panel_id, $secret->provider_server_id, $location->created_by);
        }

        $connectedServers = ConnectedServer::ownedBy($location->created_by)
            ->whereNotNull('wireguard_profile_id')
            ->get();

        foreach ($connectedServers as $connected) {
            if (! $connected->wireguardProfile) {
                continue;
            }

            ConnectServerWireguardsJob::dispatch(
                $connected->host,
                $connected->username,
                $connected->password,
                $connected->wireguardProfile->private_key,
                $location->created_by,
                $location->created_by,
            );
        }
    }
}
