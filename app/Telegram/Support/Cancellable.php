<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Nutgram;

/**
 * Shared "cancel" step for InlineMenu/Conversation classes. Wire a button with
 * callback_data 'x@cancel' to let the user abort the flow at any step.
 */
trait Cancellable
{
    public function cancel(Nutgram $bot): void
    {
        $bot->sendMessage('عملیات لغو شد.');
        $this->end();
    }
}
