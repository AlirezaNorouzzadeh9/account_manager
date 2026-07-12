<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\CheckHost\CheckHostClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Part of the periodic health check (see the servers:check-pings command,
 * run every 10 minutes via the server's crontab): pings one server from Iran
 * and, ONLY if a node fails, alerts that panel's owner with a "🔄 تغییر سرور"
 * button. A clean or inconclusive result stays silent on purpose — this is a
 * "tell me when something's wrong" check, not a status report.
 *
 * Alerts once per ongoing problem, not once per 10-minute run: ServerSecret's
 * ping_alerted flag is set on the first failing check and checked before
 * sending again, so a still-broken server doesn't re-alert every cycle. A
 * clean result clears the flag so the NEXT problem alerts again.
 */
class CheckServerPingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        protected int $panelId,
        protected string $serverId,
        protected string $ip,
        protected string $hostname,
    ) {
    }

    public function handle(CheckHostClient $checkHost, Nutgram $bot): void
    {
        $secret = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->first();

        try {
            $requestId = $checkHost->requestPing($this->ip);
        } catch (Throwable) {
            return; // check-host itself being unreachable isn't a server problem
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
            if ($secret?->ping_alerted) {
                $secret->update(['ping_alerted' => false]);
            }

            return;
        }

        // Already alerted for this ongoing problem — stay silent until it
        // clears (handled above) instead of re-sending every 10 minutes.
        if ($secret?->ping_alerted) {
            return;
        }

        $ownerId = Panel::find($this->panelId)?->created_by;

        if ($ownerId === null) {
            return;
        }

        $message = "⚠️ پینگ سرور «{$this->hostname}» ({$this->ip}) از ایران مشکل دارد:\n".
            $checkHost->formatResult($result);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('🔄 تغییر سرور', callback_data: "replace_server:{$this->panelId}:{$this->serverId}")
        );

        $bot->sendMessage($message, chat_id: $ownerId, reply_markup: $keyboard);

        $secret?->update(['ping_alerted' => true]);
    }
}
