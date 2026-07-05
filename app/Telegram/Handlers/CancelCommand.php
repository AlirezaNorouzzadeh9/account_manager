<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;

class CancelCommand
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->endConversation();
        $bot->sendMessage('عملیات لغو شد.');
        (new StartCommand())($bot);
    }
}
