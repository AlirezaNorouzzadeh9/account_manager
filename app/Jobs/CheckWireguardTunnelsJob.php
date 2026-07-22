<?php

namespace App\Jobs;

use App\Models\WireguardLocation;
use App\Services\Wireguard\LocationHealer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use SergiX44\Nutgram\Nutgram;

/**
 * Runs LOCALLY on this bot server (see wireguard:check-tunnels, every 10
 * minutes) — not against a remote server over SSH like every other check
 * here — because it's specifically probing the WireGuard tunnels already
 * live on this machine (from connecting it via "🔌 اتصال به سرور موجود").
 *
 * Reproduces the admin's own diagnostic one-liner: for every `wg show
 * interfaces` interface (named after its WireguardLocation), curl out
 * through it and see whether it actually reaches the internet. This is a
 * stronger signal than CheckWireguardLocationJob's Iran-only ping — it
 * catches a tunnel that's up but not actually passing traffic, regardless
 * of where the checker is.
 *
 * On a dead interface, hands off to LocationHealer (same one
 * CheckWireguardLocationJob uses) to re-resolve and push the fix — so the
 * two checks share one alert flag (WireguardLocation::ping_alerted) and
 * never double-notify the same underlying problem.
 */
class CheckWireguardTunnelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    protected const PROBE_SCRIPT = <<<'BASH'
        for i in $(wg show interfaces); do
          ip=$(curl --interface "$i" -s -m 8 https://ifconfig.me)
          if [ -z "$ip" ]; then ip="FAILED"; fi
          echo "${i}|${ip}"
        done
        BASH;

    public function handle(LocationHealer $healer, Nutgram $bot): void
    {
        $result = Process::timeout(200)->run(self::PROBE_SCRIPT);

        if ($result->failed()) {
            return;
        }

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            [$name, $status] = array_pad(explode('|', $line, 2), 2, null);

            if ($name === null || $status === null) {
                continue;
            }

            $location = WireguardLocation::where('name', $name)->first();

            if (! $location) {
                continue;
            }

            if ($status !== 'FAILED' && filter_var($status, FILTER_VALIDATE_IP)) {
                if ($location->ping_alerted) {
                    $location->update(['ping_alerted' => false]);
                    $bot->sendMessage(
                        "✅ تونل وایرگارد لوکیشن «{$location->name}» دوباره برقرار شد (خروجی: {$status}).",
                        chat_id: $location->created_by,
                    );
                }

                continue;
            }

            $oldIp = $location->ip;
            $newIp = $healer->heal($location);

            if ($newIp) {
                $bot->sendMessage(
                    "🔁 تونل وایرگارد لوکیشن «{$location->name}» قطع بود؛ آی‌پی از {$oldIp} به {$newIp} تغییر کرد و روی سرورهای فعال اعمال شد.",
                    chat_id: $location->created_by,
                );

                continue;
            }

            if (! $location->ping_alerted) {
                $location->update(['ping_alerted' => true]);
                $bot->sendMessage(
                    "⚠️ تونل وایرگارد لوکیشن «{$location->name}» ({$location->ip}) قطع است و ترمیم خودکار ممکن نشد. دستی بررسی کنید.",
                    chat_id: $location->created_by,
                );
            }
        }
    }
}
