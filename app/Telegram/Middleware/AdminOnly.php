<?php

namespace App\Telegram\Middleware;

use SergiX44\Nutgram\Nutgram;

class AdminOnly
{
    public function __invoke(Nutgram $bot, $next): void
    {
        $userId = $bot->userId();

        if ($userId === null || !in_array((string) $userId, config('bot.admins'), true)) {
            $bot->sendMessage('⛔️ شما اجازه‌ی استفاده از این ربات را ندارید.');
            return;
        }

        $next($bot);
    }
}
