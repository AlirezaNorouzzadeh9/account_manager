<?php

namespace App\Telegram\Handlers;

use App\Models\BotUser;
use App\Telegram\Support\FormatsRtlText;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class StartCommand
{
    use FormatsRtlText;

    public function __invoke(Nutgram $bot): void
    {
        $isOwner = BotUser::isOwner($bot->userId());

        $bot->sendMessage(text: self::text($isOwner), reply_markup: self::keyboard($isOwner));
    }

    public static function text(bool $isOwner = true): string
    {
        return self::rtl(
            "به ربات مدیریت سرورهای ابری خوش آمدید! 👋\n\n".
            "🖥 پنل‌های من — افزودن و مدیریت پنل‌های سرویس‌دهنده (مثل DigitalOcean)\n".
            "📋 سرورهای من — مشاهده و مدیریت سرورهای ساخته‌شده\n".
            "➕ ساخت سرور — ساخت یک سرور ابری جدید\n".
            ($isOwner ? "🔌 اتصال به سرور — نصب وایرگارد روی یک سرور دیگر با آی‌پی/یوزر/پسورد دستی\n" : '').
            "⚙️ تنظیمات — مدیریت لوکیشن‌ها و پروفایل‌های وایرگارد\n\n".
            'یکی از گزینه‌های زیر را انتخاب کنید:'
        );
    }

    public static function keyboard(bool $isOwner = true): InlineKeyboardMarkup
    {
        // Telegram lays out a row strictly in array order, left-to-right —
        // it does NOT auto-mirror for RTL languages — so for this
        // Persian-language bot, the logically-first button of each row is
        // listed second/last here to land on the right.
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🖥 پنل‌های من', callback_data: 'panels:menu'),
            )
            ->addRow(
                InlineKeyboardButton::make('📋 سرورهای من', callback_data: 'server:list'),
                InlineKeyboardButton::make('➕ ساخت سرور', callback_data: 'server:create'),
            );

        if ($isOwner) {
            $keyboard->addRow(
                InlineKeyboardButton::make('🔌 اتصال به سرور', callback_data: 'server:connect'),
            );
        }

        return $keyboard->addRow(
            InlineKeyboardButton::make('⚙️ تنظیمات', callback_data: 'settings:menu'),
        );
    }
}
