<?php

namespace App\Telegram\Support;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * For Conversation steps waiting on free text: a typed "/cancel" isn't
 * discoverable, so every such prompt gets an inline "🔙 بازگشت" button
 * instead. Since a step method fires for ANY update (callback or text —
 * Nutgram doesn't branch by type on its own), call backTapped($bot) first
 * thing in each receiving method to short-circuit before parsing text.
 */
trait CancellableTextStep
{
    protected function backButton(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'back')
        );
    }

    protected function backTapped(Nutgram $bot): bool
    {
        if (! $bot->isCallbackQuery()) {
            return false;
        }

        $bot->answerCallbackQuery();

        return true;
    }
}
