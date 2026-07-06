<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardConfig;
use App\Models\WireguardProfile;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Manages WireGuard profiles (named groups of configs, e.g. "پروفایل ۱" =
 * Italy+Netherlands) and the individual configs inside them. A server only
 * ever gets ONE profile's configs applied (chosen from ServerListMenu at
 * install/update time), never every saved config at once.
 */
class WireguardMenu extends InlineMenu
{
    /** 'none' (the "بدون پروفایل" bucket) or a WireguardProfile id, as a string. */
    protected ?string $currentProfile = null;
    protected ?int $currentConfigId = null;

    public function start(Nutgram $bot): void
    {
        $profiles = WireguardProfile::withCount('configs')->latest()->get();
        $unassignedCount = WireguardConfig::whereNull('wireguard_profile_id')->count();

        $this->clearButtons();

        if ($profiles->isEmpty() && $unassignedCount === 0) {
            $this->menuText("هیچ پروفایل وایرگاردی نساخته‌اید.\nموقع نود کردن یا بروزرسانی هر سرور، یکی از پروفایل‌ها انتخاب می‌شود.");
        } else {
            $this->menuText("پروفایل‌های وایرگارد:\n(موقع نود کردن/بروزرسانی هر سرور، فقط کانفیگ‌های همان پروفایل روی آن فعال می‌شوند)");

            foreach ($profiles as $profile) {
                $this->addButtonRow(InlineKeyboardButton::make(
                    "🔒 {$profile->name} ({$profile->configs_count})",
                    callback_data: "{$profile->id}@showProfile"
                ));
            }

            if ($unassignedCount > 0) {
                $this->addButtonRow(InlineKeyboardButton::make(
                    "🗂 بدون پروفایل ({$unassignedCount})",
                    callback_data: 'none@showProfile'
                ));
            }
        }

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن پروفایل جدید', callback_data: 'x@addProfile'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToSettings'));
        $this->showMenu();
    }

    public function backToSettings(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        SettingsMenu::begin($bot);
    }

    public function addProfile(Nutgram $bot): void
    {
        $this->closeMenu('نام پروفایل جدید را ارسال کنید (مثلاً: پروفایل ۱):');
        $this->next('receiveProfileName');
    }

    public function receiveProfileName(Nutgram $bot): void
    {
        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        if (WireguardProfile::where('name', $name)->exists()) {
            $bot->sendMessage('پروفایلی با این نام از قبل وجود دارد. نام دیگری بفرستید یا /cancel را بزنید:');
            return;
        }

        WireguardProfile::create(['name' => $name]);

        $bot->sendMessage("✅ پروفایل «{$name}» ساخته شد.");
        $this->start($bot);
    }

    public function showProfile(Nutgram $bot, string $data): void
    {
        $this->currentProfile = $data;
        $this->renderProfile($bot);
    }

    protected function renderProfile(Nutgram $bot): void
    {
        $isUnassignedBucket = $this->currentProfile === 'none';
        $profile = $isUnassignedBucket ? null : WireguardProfile::find((int) $this->currentProfile);

        if (! $isUnassignedBucket && ! $profile) {
            $this->start($bot);
            return;
        }

        $configs = $isUnassignedBucket
            ? WireguardConfig::whereNull('wireguard_profile_id')->latest()->get()
            : $profile->configs()->latest()->get();

        $this->clearButtons();
        $this->menuText($isUnassignedBucket ? 'کانفیگ‌های بدون پروفایل:' : "پروفایل: {$profile->name}");

        foreach ($configs as $config) {
            $this->addButtonRow(InlineKeyboardButton::make(
                "🔒 {$config->name}",
                callback_data: "{$config->id}@showConfig"
            ));
        }

        if (! $isUnassignedBucket) {
            $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن کانفیگ', callback_data: 'x@addConfig'));
            $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف پروفایل', callback_data: 'x@confirmDeleteProfile'));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function addConfig(Nutgram $bot): void
    {
        $profileId = (int) $this->currentProfile;

        $this->closeMenu();
        $this->end();
        AddWireguardConversation::begin($bot, $bot->userId(), $bot->chatId(), [$profileId]);
    }

    public function confirmDeleteProfile(Nutgram $bot): void
    {
        $profile = WireguardProfile::find((int) $this->currentProfile);

        if (! $profile) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText(
            "آیا از حذف پروفایل «{$profile->name}» مطمئن هستید؟\n".
            'کانفیگ‌های داخل آن حذف نمی‌شوند، فقط بدون پروفایل می‌مانند.'
        );
        $this->addButtonRow(InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doDeleteProfile'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToProfile'));
        $this->showMenu();
    }

    public function doDeleteProfile(Nutgram $bot): void
    {
        WireguardProfile::whereKey((int) $this->currentProfile)->delete();
        $this->setCallbackQueryOptions(['text' => 'پروفایل حذف شد.']);
        $this->start($bot);
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }

    public function backToProfile(Nutgram $bot): void
    {
        $this->renderProfile($bot);
    }

    public function showConfig(Nutgram $bot, string $data): void
    {
        $config = WireguardConfig::find((int) $data);

        if (! $config) {
            $this->renderProfile($bot);
            return;
        }

        $this->currentConfigId = $config->id;

        $this->clearButtons();
        $this->menuText("نام: {$config->name}\nپروفایل: ".($config->profile->name ?? 'بدون پروفایل'));
        $this->addButtonRow(InlineKeyboardButton::make('👁 نمایش کانفیگ', callback_data: 'x@revealConfig'));
        $this->addButtonRow(InlineKeyboardButton::make('🔀 انتقال به پروفایل دیگر', callback_data: 'x@moveConfigMenu'));

        if ($config->wireguard_profile_id !== null) {
            $this->addButtonRow(InlineKeyboardButton::make('⛔ حذف از این پروفایل', callback_data: 'x@unassignConfig'));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف کامل کانفیگ', callback_data: 'x@confirmDeleteConfig'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToProfile'));
        $this->showMenu();
    }

    public function revealConfig(Nutgram $bot): void
    {
        $config = WireguardConfig::find($this->currentConfigId);

        if ($config) {
            $bot->sendMessage($config->config);
        }

        $this->setCallbackQueryOptions(['text' => 'کانفیگ در پیام بعدی فرستاده شد.']);
    }

    public function moveConfigMenu(Nutgram $bot): void
    {
        $profiles = WireguardProfile::where('id', '!=', (int) ($this->currentProfile === 'none' ? 0 : $this->currentProfile))->get();

        $this->clearButtons();
        $this->menuText('این کانفیگ به کدام پروفایل منتقل شود؟');

        foreach ($profiles as $profile) {
            $this->addButtonRow(InlineKeyboardButton::make($profile->name, callback_data: "{$profile->id}@doMoveConfig"));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToConfig'));
        $this->showMenu();
    }

    public function doMoveConfig(Nutgram $bot, string $data): void
    {
        WireguardConfig::whereKey($this->currentConfigId)->update(['wireguard_profile_id' => (int) $data]);
        $this->setCallbackQueryOptions(['text' => 'کانفیگ منتقل شد.']);
        $this->renderProfile($bot);
    }

    public function unassignConfig(Nutgram $bot): void
    {
        WireguardConfig::whereKey($this->currentConfigId)->update(['wireguard_profile_id' => null]);
        $this->setCallbackQueryOptions(['text' => 'از پروفایل حذف شد.']);
        $this->renderProfile($bot);
    }

    public function backToConfig(Nutgram $bot): void
    {
        $this->showConfig($bot, (string) $this->currentConfigId);
    }

    public function confirmDeleteConfig(Nutgram $bot): void
    {
        $config = WireguardConfig::find($this->currentConfigId);

        if (! $config) {
            $this->renderProfile($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("آیا از حذف کامل کانفیگ «{$config->name}» مطمئن هستید؟");
        $this->addButtonRow(InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doDeleteConfig'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToConfig'));
        $this->showMenu();
    }

    public function doDeleteConfig(Nutgram $bot): void
    {
        WireguardConfig::whereKey($this->currentConfigId)->delete();
        $this->setCallbackQueryOptions(['text' => 'حذف شد.']);
        $this->renderProfile($bot);
    }
}
