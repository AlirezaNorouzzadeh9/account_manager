<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Manages WireGuard "profiles" — a name + PrivateKey identity picked per
 * server when installing/updating WireGuard, so different servers can
 * present different client identities to the same set of WireguardLocations.
 */
class WireguardProfileMenu extends InlineMenu
{
    use GridButtons;

    protected ?int $currentProfileId = null;

    public function start(Nutgram $bot): void
    {
        $profiles = WireguardProfile::orderBy('name')->get();

        $this->clearButtons();
        $this->menuText(
            $profiles->isEmpty()
                ? "هیچ پروفایلی اضافه نکرده‌اید.\nبا «افزودن پروفایل» شروع کنید."
                : 'پروفایل‌های وایرگارد:'
        );

        $this->addButtonGrid($profiles->map(fn (WireguardProfile $profile) => InlineKeyboardButton::make(
            "🪪 {$profile->name}",
            callback_data: "{$profile->id}@showProfile"
        ))->all());

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن پروفایل', callback_data: 'x@addProfile'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToWireguard'));
        $this->showMenu();
    }

    public function backToWireguard(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        WireguardMenu::begin($bot);
    }

    public function addProfile(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        AddWireguardProfileConversation::begin($bot);
    }

    public function showProfile(Nutgram $bot, string $data): void
    {
        $profile = WireguardProfile::find((int) $data);

        if (! $profile) {
            $this->start($bot);
            return;
        }

        $this->currentProfileId = $profile->id;

        $this->clearButtons();
        $this->menuText("نام: {$profile->name}");
        $this->addButtonRow(InlineKeyboardButton::make('👁 نمایش PrivateKey', callback_data: 'x@revealPrivateKey'));
        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف پروفایل', callback_data: 'x@confirmDeleteProfile'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function revealPrivateKey(Nutgram $bot): void
    {
        $profile = WireguardProfile::find($this->currentProfileId);

        if ($profile) {
            $bot->sendMessage("`{$profile->private_key}`", parse_mode: 'Markdown');
        }

        $this->setCallbackQueryOptions(['text' => 'PrivateKey در پیام بعدی فرستاده شد.']);
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }

    public function confirmDeleteProfile(Nutgram $bot): void
    {
        $profile = WireguardProfile::find($this->currentProfileId);

        if (! $profile) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("آیا از حذف پروفایل «{$profile->name}» مطمئن هستید؟\nسرورهایی که از این پروفایل استفاده می‌کنند بدون وایرگارد می‌مانند تا دوباره پروفایل انتخاب کنید.");
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@showProfileAgain'),
            InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doDeleteProfile'),
        );
        $this->showMenu();
    }

    public function showProfileAgain(Nutgram $bot): void
    {
        $this->showProfile($bot, (string) $this->currentProfileId);
    }

    public function doDeleteProfile(Nutgram $bot): void
    {
        WireguardProfile::whereKey($this->currentProfileId)->delete();
        $this->setCallbackQueryOptions(['text' => 'پروفایل حذف شد.']);
        $this->start($bot);
    }
}
