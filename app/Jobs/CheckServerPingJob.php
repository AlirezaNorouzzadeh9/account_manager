<?php

namespace App\Jobs;

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
 * and, ONLY if a node fails, alerts every admin with a "🔄 تغییر سرور"
 * button. A clean or inconclusive result stays silent on purpose — this is a
 * "tell me when something's wrong" check, not a status report.
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

        // No result at all (check-host hiccup) or every node ok => nothing to report.
        if ($result === null || $checkHost->allNodesOk($result)) {
            return;
        }

        $message = "⚠️ پینگ سرور «{$this->hostname}» ({$this->ip}) از ایران مشکل دارد:\n".
            $checkHost->formatResult($result);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('🔄 تغییر سرور', callback_data: "replace_server:{$this->panelId}:{$this->serverId}")
        );

        foreach (config('bot.admins') as $adminId) {
            $bot->sendMessage($message, chat_id: (int) $adminId, reply_markup: $keyboard);
        }
    }
}
