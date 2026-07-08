<?php

namespace App\Telegram\Conversations;

use App\Telegram\Support\Cancellable;
use App\Telegram\Support\EditsInPlace;
use App\Telegram\Support\FormatsRtlText;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class SettingsMenu extends InlineMenu
{
    use Cancellable;
    use EditsInPlace;
    use FormatsRtlText;

    public function start(Nutgram $bot): void
    {
        $this->editInPlaceFromCallback($bot);
        $this->clearButtons();
        $this->menuText($this->rtl(
            "⚙️ تنظیمات\n\n".
            "🔒 مدیریت وایرگاردها — لوکیشن‌های وایرگارد سرورها\n".
            "🪪 پروفایل‌ها — مدیریت پروفایل‌های وایرگارد (PrivateKey هر سرور)\n\n".
            'یکی از گزینه‌های زیر را انتخاب کنید:'
        ));
        $this->addButtonRow(InlineKeyboardButton::make('🔒 مدیریت وایرگاردها', callback_data: 'x@wireguard'));
        $this->addButtonRow(InlineKeyboardButton::make('🪪 پروفایل‌ها', callback_data: 'x@profiles'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت به منوی اصلی', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function wireguard(Nutgram $bot): void
    {
        // endWithoutClosing(): WireguardMenu edits this same message in
        // place; a bare end() would delete it first (see EditsInPlace).
        $this->endWithoutClosing();
        WireguardMenu::begin($bot);
    }

    public function profiles(Nutgram $bot): void
    {
        $this->endWithoutClosing();
        WireguardProfileMenu::begin($bot);
    }
}
