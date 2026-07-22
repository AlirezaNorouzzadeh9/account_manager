<?php

namespace App\Telegram\Support;

use App\Models\BotUser;
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
        $messageId = $this->messageId;
        $chatId = $this->chatId;

        // Null these out before end() so InlineMenu::closing() doesn't delete
        // the message — we're about to edit it into the main menu instead.
        $this->messageId = null;
        $this->chatId = null;

        $this->end();

        if ($messageId && $chatId) {
            $isOwner = BotUser::isOwner($bot->userId());

            $bot->editMessageText(
                text: StartCommand::text($isOwner),
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: StartCommand::keyboard($isOwner),
            );

            return;
        }

        (new StartCommand())($bot);
    }
}
