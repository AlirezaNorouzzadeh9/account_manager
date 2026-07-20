<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\EditsInPlace;
use App\Telegram\Support\FormatsRtlText;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Manages the flat list of WireGuard locations — every server that has
 * WireGuard enabled gets ALL of these. Per-server identity (PrivateKey) is
 * a separate "profile" (see WireguardProfileMenu), picked when installing/
 * updating WireGuard on a specific server.
 */
class WireguardMenu extends InlineMenu
{
    use EditsInPlace;
    use FormatsRtlText;
    use GridButtons;

    protected ?int $currentLocationId = null;

    /** The Telegram user this conversation instance belongs to — set once in start(). */
    protected ?int $ownerId = null;

    public function start(Nutgram $bot, ?int $focusLocationId = null, bool $justCreated = false): void
    {
        $this->ownerId = $bot->userId();
        $this->editInPlaceFromCallback($bot);

        if ($focusLocationId !== null) {
            $this->showLocation($bot, (string) $focusLocationId, $justCreated);

            return;
        }

        $locations = WireguardLocation::ownedBy($this->ownerId)->orderBy('name')->get();

        $intro = "🔒 لوکیشن‌های وایرگارد — روی هر سروری که وایرگارد فعال شود، همه‌ی این‌ها با هم فعال می‌شوند\n".
            "➕ افزودن لوکیشن — یک لوکیشن جدید اضافه کنید\n\n";

        $this->clearButtons();
        $this->menuText($this->rtl(
            $intro.(
                $locations->isEmpty()
                    ? "هیچ لوکیشن وایرگاردی اضافه نکرده‌اید.\nبا «افزودن لوکیشن» شروع کنید."
                    : 'لیست لوکیشن‌ها:'
            )
        ));

        $this->addButtonGrid($locations->map(fn (WireguardLocation $location) => InlineKeyboardButton::make(
            $location->country ? "{$location->flag()} {$location->name} ({$location->country})" : "🔒 {$location->name}",
            callback_data: "{$location->id}@showLocation"
        ))->all());

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن لوکیشن', callback_data: 'x@addLocation'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToSettings'));
        $this->showMenu();
    }

    public function backToSettings(Nutgram $bot): void
    {
        $this->endWithoutClosing();
        SettingsMenu::begin($bot);
    }

    public function addLocation(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        AddWireguardConversation::begin($bot);
    }

    public function showLocation(Nutgram $bot, string $data, bool $justCreated = false): void
    {
        $location = WireguardLocation::ownedBy($this->ownerId)->find((int) $data);

        if (! $location) {
            $this->start($bot);
            return;
        }

        $this->currentLocationId = $location->id;

        $countryLine = $location->country
            ? "کشور: {$location->flag()} `{$location->country}`\n"
            : "کشور تنظیم نشده.\n";

        $intro = "🌍 تنظیم کشور — تنظیم نام کشور (فقط برای تشخیص خودتان)\n".
            "📍 تنظیم آی‌پی — تغییر آی‌پی این لوکیشن\n".
            "🗑 حذف لوکیشن — حذف این لوکیشن\n".
            "🔙 بازگشت — بازگشت به لیست لوکیشن‌ها\n\n";

        $this->clearButtons();
        $this->menuText(
            $this->rtl(
                $intro.
                ($justCreated ? "✅ لوکیشن «{$location->name}» ذخیره شد.\n\n" : '').
                "نام: `{$location->name}`\n".
                $countryLine.
                "آی‌پی: `{$location->ip}`\n".
                "PublicKey سرور: `{$location->server_public_key}`"
            ),
            ['parse_mode' => 'Markdown']
        );
        $this->addButtonRow(InlineKeyboardButton::make('🌍 تنظیم کشور', callback_data: 'x@setCountry'));
        $this->addButtonRow(InlineKeyboardButton::make('📍 تنظیم آی‌پی', callback_data: 'x@setIp'));
        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف لوکیشن', callback_data: 'x@confirmDeleteLocation'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function setCountry(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        SetWireguardLocationCountryConversation::begin($bot, $bot->userId(), $bot->chatId(), [$this->currentLocationId]);
    }

    public function setIp(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        SetWireguardLocationIpConversation::begin($bot, $bot->userId(), $bot->chatId(), [$this->currentLocationId]);
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }

    public function confirmDeleteLocation(Nutgram $bot): void
    {
        $location = WireguardLocation::ownedBy($this->ownerId)->find($this->currentLocationId);

        if (! $location) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("آیا از حذف لوکیشن «{$location->name}» مطمئن هستید؟");
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@showLocationAgain'),
            InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doDeleteLocation'),
        );
        $this->showMenu();
    }

    public function showLocationAgain(Nutgram $bot): void
    {
        $this->showLocation($bot, (string) $this->currentLocationId);
    }

    public function doDeleteLocation(Nutgram $bot): void
    {
        WireguardLocation::ownedBy($this->ownerId)->whereKey($this->currentLocationId)->delete();
        $this->setCallbackQueryOptions(['text' => 'لوکیشن حذف شد.']);
        $this->start($bot);
    }
}
