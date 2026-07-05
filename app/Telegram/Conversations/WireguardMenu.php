<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardConfig;
use App\Telegram\Support\Cancellable;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class WireguardMenu extends InlineMenu
{
    use Cancellable;

    public function start(Nutgram $bot): void
    {
        $configs = WireguardConfig::query()->latest()->get();

        $this->clearButtons();

        if ($configs->isEmpty()) {
            $this->menuText("هیچ کانفیگ وایرگاردی ذخیره نشده.\nموقع نود کردن هر سرور، همه‌ی کانفیگ‌های ذخیره‌شده روی آن فعال می‌شوند.");
        } else {
            $this->menuText("کانفیگ‌های وایرگارد ذخیره‌شده:\n(موقع نود کردن هر سرور، همه‌شان روی آن فعال می‌شوند)");

            foreach ($configs as $config) {
                $this->addButtonRow(InlineKeyboardButton::make(
                    "🔒 {$config->name}",
                    callback_data: "{$config->id}@showConfig"
                ));
            }
        }

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن وایرگارد', callback_data: 'x@addConfig'));
        $this->addButtonRow(InlineKeyboardButton::make('❌ بستن', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function addConfig(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        AddWireguardConversation::begin($bot);
    }

    public function showConfig(Nutgram $bot, string $data): void
    {
        $config = WireguardConfig::find((int) $data);

        if (! $config) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("نام: {$config->name}");
        $this->addButtonRow(InlineKeyboardButton::make('👁 نمایش کانفیگ', callback_data: "{$config->id}@revealConfig"));
        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف', callback_data: "{$config->id}@confirmDelete"));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function revealConfig(Nutgram $bot, string $data): void
    {
        $config = WireguardConfig::find((int) $data);

        if ($config) {
            $bot->sendMessage($config->config);
        }

        $this->setCallbackQueryOptions(['text' => 'کانفیگ در پیام بعدی فرستاده شد.']);
    }

    public function confirmDelete(Nutgram $bot, string $data): void
    {
        $config = WireguardConfig::find((int) $data);

        if (! $config) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("آیا از حذف کانفیگ «{$config->name}» مطمئن هستید؟");
        $this->addButtonRow(InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: "{$config->id}@doDelete"));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function doDelete(Nutgram $bot, string $data): void
    {
        WireguardConfig::whereKey((int) $data)->delete();
        $this->setCallbackQueryOptions(['text' => 'حذف شد.']);
        $this->start($bot);
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }
}
