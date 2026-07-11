<?php

namespace App\Telegram\Conversations;

use App\Models\BotUser;
use App\Telegram\Support\EditsInPlace;
use App\Telegram\Support\FormatsRtlText;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Owner-only: manages the shared whitelist of Telegram users granted access
 * to the bot beyond the fixed ADMIN_TELEGRAM_IDS ("owners") — see
 * BotUser::isOwner()/isAllowed(). Any owner can add/revoke any granted user
 * here (it's one shared list, not per-owner), but this menu does NOT expose
 * other users' panels/servers/WireGuard data — every user's own resources
 * stay isolated regardless of who granted them access.
 */
class UserManagementMenu extends InlineMenu
{
    use EditsInPlace;
    use FormatsRtlText;
    use GridButtons;

    protected ?int $currentUserId = null;

    public function start(Nutgram $bot, ?int $focusUserId = null, bool $justCreated = false): void
    {
        $this->editInPlaceFromCallback($bot);

        if (! BotUser::isOwner($bot->userId())) {
            $this->closeMenu('⛔️ این بخش فقط برای مالکین ربات است.');
            $this->end();

            return;
        }

        if ($focusUserId !== null) {
            $this->showUser($bot, (string) $focusUserId, $justCreated);

            return;
        }

        $users = BotUser::orderByDesc('id')->get();

        $intro = "👥 مدیریت کاربران — کاربرانی که علاوه بر مالکین ربات اجازه‌ی استفاده دارند\n".
            "➕ افزودن کاربر — دسترسی یک کاربر جدید با آیدی عددی تلگرام\n\n".
            "هر کاربر فقط به پنل‌ها، سرورها و وایرگاردهای خودش دسترسی دارد.\n\n";

        $this->clearButtons();
        $this->menuText($this->rtl(
            $intro.(
                $users->isEmpty()
                    ? 'هیچ کاربری اضافه نشده.'
                    : 'لیست کاربران:'
            )
        ));

        $this->addButtonGrid($users->map(fn (BotUser $user) => InlineKeyboardButton::make(
            $user->label ? "👤 {$user->label} ({$user->telegram_id})" : "👤 {$user->telegram_id}",
            callback_data: "{$user->id}@showUser"
        ))->all());

        $this->addButtonRow(InlineKeyboardButton::make('➕ افزودن کاربر', callback_data: 'x@addUser'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToSettings'));
        $this->showMenu();
    }

    public function backToSettings(Nutgram $bot): void
    {
        $this->endWithoutClosing();
        SettingsMenu::begin($bot);
    }

    public function addUser(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        AddBotUserConversation::begin($bot);
    }

    public function showUser(Nutgram $bot, string $data, bool $justCreated = false): void
    {
        $user = BotUser::find((int) $data);

        if (! $user) {
            $this->start($bot);

            return;
        }

        $this->currentUserId = $user->id;

        $intro = "🗑 حذف دسترسی — این کاربر دیگر نمی‌تواند از ربات استفاده کند (پنل‌ها و اطلاعات او حذف نمی‌شود)\n".
            "🔙 بازگشت — بازگشت به لیست کاربران\n\n";

        $this->clearButtons();
        $this->menuText(
            $this->rtl(
                $intro.
                ($justCreated ? "✅ دسترسی کاربر `{$user->telegram_id}` اضافه شد.\n\n" : '').
                "آیدی تلگرام: `{$user->telegram_id}`\n".
                'برچسب: '.($user->label ?: 'تنظیم نشده')
            ),
            ['parse_mode' => 'Markdown']
        );
        $this->addButtonRow(InlineKeyboardButton::make('🗑 حذف دسترسی', callback_data: 'x@confirmRevoke'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function backToList(Nutgram $bot): void
    {
        $this->start($bot);
    }

    public function confirmRevoke(Nutgram $bot): void
    {
        $user = BotUser::find($this->currentUserId);

        if (! $user) {
            $this->start($bot);

            return;
        }

        $this->clearButtons();
        $this->menuText("آیا از حذف دسترسی کاربر «{$user->telegram_id}» مطمئن هستید؟");
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@showUserAgain'),
            InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doRevoke'),
        );
        $this->showMenu();
    }

    public function showUserAgain(Nutgram $bot): void
    {
        $this->showUser($bot, (string) $this->currentUserId);
    }

    public function doRevoke(Nutgram $bot): void
    {
        BotUser::whereKey($this->currentUserId)->delete();
        $this->setCallbackQueryOptions(['text' => 'دسترسی کاربر حذف شد.']);
        $this->start($bot);
    }
}
