<?php

namespace App\Jobs;

use App\Models\WireguardLocation;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Wireguard\LocationHealer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Health check for one WireGuard location (see wireguard:check-locations,
 * run every 3 minutes): pings the location's ip from Iran via check-host.net
 * — a different signal than CheckWireguardTunnelsJob's local tunnel probe,
 * catching an endpoint that's unreachable specifically from Iran even while
 * the tunnel itself still comes up fine.
 *
 * Only runs for locations that HAVE a hostname set (WireguardMenu's
 * "🌐 تنظیم دامنه" is optional) — same "nothing to check yet" skip as
 * CheckWireguardProfileJob does for a profile with no own_ip.
 *
 * On a real failure, hands off to LocationHealer to re-resolve the hostname
 * and push the fix to every affected server. Alerts once per ongoing
 * problem via WireguardLocation::ping_alerted, same pattern as
 * ServerSecret::ping_alerted/WireguardProfile::ping_alerted — shared with
 * CheckWireguardTunnelsJob so the two checks don't double-alert the same
 * underlying problem.
 */
class CheckWireguardLocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(protected int $locationId)
    {
    }

    public function handle(CheckHostClient $checkHost, LocationHealer $healer, Nutgram $bot): void
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

        $oldIp = $location->ip;
        $newIp = $healer->heal($location);

        if ($newIp) {
            $bot->sendMessage(
                "🔁 آی‌پی لوکیشن «{$location->name}» چون از ایران در دسترس نبود، از {$oldIp} به {$newIp} تغییر کرد؛ در حال بروزرسانی سرورهای فعال...",
                chat_id: $location->created_by,
            );

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
}
