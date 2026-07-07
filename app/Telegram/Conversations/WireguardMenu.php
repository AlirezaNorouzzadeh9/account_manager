<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Manages the flat list of WireGuard locations. Every server that has
 * WireGuard enabled gets ALL of these — there's no more per-server subset
 * (see the old "profile" concept this replaced).
 */
class WireguardMenu extends InlineMenu
{
    use GridButtons;

    protected ?int $currentLocationId = null;

    public function start(Nutgram $bot): void
    {
        $locations = WireguardLocation::orderBy('name')->get();

        $this->clearButtons();
        $this->menuText(
            $locations->isEmpty()
                ? "هیچ لوکیشن وایرگاردی اضافه نکرده‌اید.\nبا «افزودن لوکیشن» شروع کنید."
                : "لوکیشن‌های وایرگارد:\n(روی هر سروری که وایرگارد فعال شود، همه‌ی این‌ها با هم فعال می‌شوند)"
        );

        $this->addButtonGrid($locations->map(fn (WireguardLocation $location) => InlineKeyboardButton::make(
            "🔒 {$location->name}",
            callback_data: "{$location->id}@showLocation"
        ))->all());

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن لوکیشن', callback_data: 'x@addLocation'));
        $this->addButtonRow(InlineKeyboardButton::make('⚙️ تنظیمات پایه', callback_data: 'x@editSettings'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToSettings'));
        $this->showMenu();
    }

    public function backToSettings(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        SettingsMenu::begin($bot);
    }

    public function addLocation(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        AddWireguardConversation::begin($bot);
    }

    public function editSettings(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        WireguardSettingsConversation::begin($bot);
    }

    public function showLocation(Nutgram $bot, string $data): void
    {
        $location = WireguardLocation::find((int) $data);

        if (! $location) {
            $this->start($bot);
            return;
        }

        $this->currentLocationId = $location->id;

        $this->clearButtons();
        $this->menuText(
            "نام: {$location->name}\n".
            "آی‌پی: {$location->ip}\n".
            "PublicKey سرور: {$location->server_public_key}"
        );
        $this->addButtonRow(InlineKeyboardButton::make('👁 نمایش PrivateKey', callback_data: 'x@revealPrivateKey'));
        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف لوکیشن', callback_data: 'x@confirmDeleteLocation'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function revealPrivateKey(Nutgram $bot): void
    {
        $location = WireguardLocation::find($this->currentLocationId);

        if ($location) {
            $bot->sendMessage($location->private_key);
        }

        $this->setCallbackQueryOptions(['text' => 'PrivateKey در پیام بعدی فرستاده شد.']);
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }

    public function confirmDeleteLocation(Nutgram $bot): void
    {
        $location = WireguardLocation::find($this->currentLocationId);

        if (! $location) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("آیا از حذف لوکیشن «{$location->name}» مطمئن هستید؟");
        $this->addButtonRow(
            InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doDeleteLocation'),
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@showLocationAgain'),
        );
        $this->showMenu();
    }

    public function showLocationAgain(Nutgram $bot): void
    {
        $this->showLocation($bot, (string) $this->currentLocationId);
    }

    public function doDeleteLocation(Nutgram $bot): void
    {
        WireguardLocation::whereKey($this->currentLocationId)->delete();
        $this->setCallbackQueryOptions(['text' => 'لوکیشن حذف شد.']);
        $this->start($bot);
    }
}
