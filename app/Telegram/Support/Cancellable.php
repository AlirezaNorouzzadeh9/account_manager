<?php

namespace App\Telegram\Support;

use App\Telegram\Handlers\StartCommand;
use SergiX44\Nutgram\Nutgram;

/**
 * Shared "cancel"/"close" step for InlineMenu/Conversation classes. Wire a
 * button with callback_data 'x@cancel' to let the user abort the flow at any
 * step, or close a menu — either way it drops them back at the main menu.
 */
trait Cancellable
{
    public function cancel(Nutgram $bot): void
    {
        $this->end();
        (new StartCommand())($bot);
    }
}
