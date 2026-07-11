<?php

namespace App\Telegram\Conversations;

use App\Jobs\ConnectServerWireguardsJob;
use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use App\Telegram\Support\EditsInPlace;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Connects to a server the bot never provisioned itself (given a raw
 * IP/username/password), tears down every WireGuard interface already on
 * it, and rebuilds ALL saved WireguardLocations there using a chosen
 * profile's PrivateKey — the same underlying operation ServerListMenu's
 * "🔄 آپدیت وایرگارد" does for panel-provisioned servers (see
 * PasarguardNodeInstaller::updateWireguards()), just without needing a
 * Panel/ServerSecret record to look the credentials up from.
 */
class ConnectServerConversation extends InlineMenu
{
    use Cancellable;
    use CancellableTextStep;
    use EditsInPlace;
    use GridButtons;

    protected ?string $host = null;
    protected ?string $username = null;
    protected ?string $password = null;
    protected ?int $profileId = null;

    public function start(Nutgram $bot): void
    {
        $this->editInPlaceFromCallback($bot);
        $this->closeMenu('آی‌پی سرور را ارسال کنید:', ['reply_markup' => $this->backButton()]);
        $this->next('receiveHost');
    }

    public function receiveHost(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->cancel($bot);

            return;
        }

        $host = trim((string) $bot->message()?->text);

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            $bot->sendMessage('آی‌پی نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());

            return;
        }

        $this->host = $host;
        $bot->sendMessage('نام کاربری SSH را ارسال کنید (مثلاً: root):', reply_markup: $this->backButton());
        $this->next('receiveUsername');
    }

    public function receiveUsername(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->cancel($bot);

            return;
        }

        $username = trim((string) $bot->message()?->text);

        if ($username === '') {
            $bot->sendMessage('نام کاربری نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());

            return;
        }

        $this->username = $username;
        $bot->sendMessage('پسورد SSH را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receivePassword');
    }

    public function receivePassword(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->cancel($bot);

            return;
        }

        $password = (string) $bot->message()?->text;

        if ($password === '') {
            $bot->sendMessage('پسورد نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());

            return;
        }

        $this->password = $password;
        $this->showProfiles($bot);
    }

    protected function showProfiles(Nutgram $bot): void
    {
        $profiles = WireguardProfile::ownedBy($bot->userId())->orderBy('name')->get();

        if ($profiles->isEmpty()) {
            $bot->sendMessage('هیچ پروفایل وایرگاردی ثبت نشده. ابتدا از ⚙️ تنظیمات یک پروفایل اضافه کنید.');
            $this->end();

            return;
        }

        $this->clearButtons();
        $this->menuText(
            "سرور: {$this->host}\n\n".
            "⚠️ با انتخاب پروفایل، تمام اینترفیس‌های وایرگارد فعلی این سرور حذف و همه‌ی لوکیشن‌های ذخیره‌شده با آن پروفایل دوباره ساخته می‌شوند.\n".
            'کدام پروفایل استفاده شود؟'
        );

        $this->addButtonGrid($profiles->map(fn (WireguardProfile $p) => InlineKeyboardButton::make(
            "🪪 {$p->name}",
            callback_data: "{$p->id}@chooseProfile"
        ))->all());

        $this->addButtonRow(InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function chooseProfile(Nutgram $bot, string $data): void
    {
        $profile = WireguardProfile::ownedBy($bot->userId())->find((int) $data);

        if (! $profile) {
            $this->showProfiles($bot);

            return;
        }

        $this->profileId = $profile->id;

        $this->clearButtons();
        $this->menuText(
            "سرور: {$this->host}\n".
            "پروفایل: {$profile->name}\n\n".
            'همه‌ی وایرگاردهای فعلی این سرور حذف و دوباره با این پروفایل ساخته می‌شوند. ادامه بدهم؟'
        );
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@cancel'),
            InlineKeyboardButton::make('✅ بله، انجام بده', callback_data: 'yes@confirm'),
        );
        $this->showMenu();
    }

    public function confirm(Nutgram $bot): void
    {
        $profile = WireguardProfile::ownedBy($bot->userId())->find($this->profileId);

        if (! $profile) {
            $this->closeMenu('❌ این پروفایل دیگر وجود ندارد.');
            $this->end();

            return;
        }

        ConnectServerWireguardsJob::dispatch(
            $this->host,
            $this->username,
            $this->password,
            $profile->private_key,
            $bot->chatId(),
            $bot->userId(),
        );

        $this->closeMenu(
            "⏳ درخواست بروزرسانی وایرگاردهای سرور {$this->host} با پروفایل «{$profile->name}» ثبت شد.\n".
            'نتیجه به‌زودی ارسال می‌شود.'
        );
        $this->end();
    }
}
