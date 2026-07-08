<?php

namespace App\Telegram\Conversations;

use App\Models\Panel;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\EditsInPlace;
use App\Telegram\Support\FormatsRtlText;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class PanelsMenu extends InlineMenu
{
    use Cancellable;
    use EditsInPlace;
    use FormatsRtlText;
    use GridButtons;

    public function start(Nutgram $bot, ?int $focusPanelId = null, bool $justCreated = false): void
    {
        $this->editInPlaceFromCallback($bot);

        if ($focusPanelId !== null) {
            $this->showPanel($bot, (string) $focusPanelId, $justCreated);

            return;
        }

        $panels = Panel::query()->latest()->get();

        $intro = "🖥 پنل‌های سرویس‌دهنده — هر پنل یک اکانت ارائه‌دهنده (مثل DigitalOcean) است که سرورها رویش ساخته می‌شوند\n".
            "➕ افزودن پنل — یک پنل جدید اضافه کنید\n\n";

        $this->clearButtons();

        if ($panels->isEmpty()) {
            $this->menuText($this->rtl($intro.'هنوز هیچ پنلی اضافه نکرده‌اید.'));
        } else {
            $this->menuText($this->rtl($intro.'پنل‌های شما:'));
            $this->addButtonGrid($panels->map(function (Panel $panel) {
                $status = $panel->is_active ? '🟢' : '🔴';

                return InlineKeyboardButton::make(
                    "{$status} {$panel->name} ({$panel->provider->label()})",
                    callback_data: "{$panel->id}@showPanel"
                );
            })->all());
        }

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن پنل', callback_data: 'x@addPanel'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function addPanel(Nutgram $bot): void
    {
        $this->endWithoutClosing();
        AddPanelConversation::begin($bot);
    }

    public function showPanel(Nutgram $bot, string $data, bool $justCreated = false): void
    {
        $panel = Panel::find((int) $data);

        if (! $panel) {
            $this->setCallbackQueryOptions(['text' => 'این پنل دیگر وجود ندارد.', 'show_alert' => true]);
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText($this->rtl(
            ($justCreated ? "✅ پنل «{$panel->name}» با موفقیت اضافه شد.\n\n" : '').
            "نام: {$panel->name}\n".
            "ارائه‌دهنده: {$panel->provider->label()}\n".
            'ایمیل: '.($panel->meta['email'] ?? '-')
        ));
        $this->addButtonRow(InlineKeyboardButton::make('✏️ ویرایش نام', callback_data: "{$panel->id}@renamePanel"));
        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف پنل', callback_data: "{$panel->id}@confirmDelete"));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function renamePanel(Nutgram $bot, string $data): void
    {
        $this->endWithoutClosing();
        RenamePanelConversation::begin($bot, $bot->userId(), $bot->chatId(), [(int) $data]);
    }

    public function confirmDelete(Nutgram $bot, string $data): void
    {
        $panel = Panel::find((int) $data);

        if (! $panel) {
            $this->start($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText(
            "آیا از حذف پنل «{$panel->name}» مطمئن هستید؟\n".
            'این کار سرورهای واقعی شما را حذف نمی‌کند، فقط دسترسی ربات به آن‌ها قطع می‌شود.'
        );
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToList'),
            InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: "{$panel->id}@doDelete"),
        );
        $this->showMenu();
    }

    public function doDelete(Nutgram $bot, string $data): void
    {
        Panel::whereKey((int) $data)->delete();
        $this->setCallbackQueryOptions(['text' => 'پنل حذف شد.']);
        $this->start($bot);
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }
}
