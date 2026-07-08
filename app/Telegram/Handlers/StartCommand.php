<?php

namespace App\Telegram\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(text: self::text(), reply_markup: self::keyboard());
    }

    public static function text(): string
    {
        return "به ربات مدیریت سرورهای ابری خوش آمدید.\nیکی از گزینه‌های زیر را انتخاب کنید:";
    }

    public static function keyboard(): InlineKeyboardMarkup
    {
        // Telegram lays out a row strictly in array order, left-to-right —
        // it does NOT auto-mirror for RTL languages — so for this
        // Persian-language bot, the logically-first button of each row is
        // listed second/last here to land on the right.
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🖥 پنل‌های من', callback_data: 'panels:menu'),
            )
            ->addRow(
                InlineKeyboardButton::make('📋 سرورهای من', callback_data: 'server:list'),
                InlineKeyboardButton::make('➕ ساخت سرور', callback_data: 'server:create'),
            )
            ->addRow(
                InlineKeyboardButton::make('⚙️ تنظیمات', callback_data: 'settings:menu'),
            );
    }
}
