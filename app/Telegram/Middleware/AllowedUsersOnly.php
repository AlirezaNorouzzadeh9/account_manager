<?php

namespace App\Telegram\Middleware;

use App\Models\BotUser;
use SergiX44\Nutgram\Nutgram;

/**
 * Lets through owners (ADMIN_TELEGRAM_IDS env list) and any Telegram user an
 * owner has granted access to via the "👥 مدیریت کاربران" menu (see
 * BotUser). Everyone let through still only sees/manages their own
 * panels/servers/WireGuard data — this middleware only gates "can use the
 * bot at all", not per-resource ownership.
 */
class AllowedUsersOnly
{
    public function __invoke(Nutgram $bot, $next): void
    {
        $userId = $bot->userId();

        if (! BotUser::isAllowed($userId)) {
            $bot->sendMessage('⛔️ شما اجازه‌ی استفاده از این ربات را ندارید.');
            return;
        }

        $next($bot);
    }
}
