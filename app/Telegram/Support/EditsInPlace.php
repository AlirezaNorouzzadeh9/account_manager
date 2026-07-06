<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Nutgram;

/**
 * InlineMenu::showMenu() only edits an existing message once $messageId/
 * $chatId are already known to the conversation instance — otherwise its
 * very first call always sends a brand new message. That's correct for
 * conversations started from a plain command, but when a conversation is
 * started by tapping a button on another menu (e.g. the main menu), we want
 * that first showMenu() to replace the tapped message instead of leaving it
 * behind and sending a second one. Call this at the top of start() to do so.
 */
trait EditsInPlace
{
    protected function editInPlaceFromCallback(Nutgram $bot): void
    {
        $message = $bot->callbackQuery()?->message;

        if ($message === null) {
            return;
        }

        $this->messageId = $message->message_id;
        $this->chatId = $message->chat->id;
    }
}
