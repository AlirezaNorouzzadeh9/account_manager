<?php

namespace App\Telegram\Conversations;

use App\Telegram\Support\Cancellable;
use App\Telegram\Support\EditsInPlace;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class SettingsMenu extends InlineMenu
{
    use Cancellable;
    use EditsInPlace;

    public function start(Nutgram $bot): void
    {
        $this->editInPlaceFromCallback($bot);
        $this->clearButtons();
        $this->menuText('⚙️ تنظیمات:');
        $this->addButtonRow(InlineKeyboardButton::make('🔒 وایرگاردها', callback_data: 'x@wireguard'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function wireguard(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        WireguardMenu::begin($bot);
    }
}
