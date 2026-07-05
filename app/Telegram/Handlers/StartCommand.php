<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: "به ربات مدیریت سرورهای ابری خوش آمدید.\nیکی از گزینه‌های زیر را انتخاب کنید:",
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🖥 پنل‌های من', callback_data: 'panels:menu'))
                ->addRow(InlineKeyboardButton::make('➕ ساخت سرور', callback_data: 'server:create'))
                ->addRow(InlineKeyboardButton::make('📋 سرورهای من', callback_data: 'server:list')),
        );
    }
}
