<?php

namespace App\Telegram\Conversations;

use App\Enums\Provider;
use App\Models\Panel;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class AddPanelConversation extends InlineMenu
{
    protected ?string $provider = null;
    protected ?string $name = null;

    public function start(Nutgram $bot): void
    {
        $this->clearButtons();
        $this->menuText('کدام دیتاسنتر را می‌خواهید اضافه کنید؟');

        foreach (Provider::cases() as $provider) {
            $label = $provider->label().($provider->isAvailable() ? '' : ' (به‌زودی)');
            $this->addButtonRow(InlineKeyboardButton::make($label, callback_data: "{$provider->value}@chooseProvider"));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToPanels'));
        $this->showMenu();
    }

    public function backToPanels(Nutgram $bot): void
    {
        $this->closeMenu();
        $this->end();
        PanelsMenu::begin($bot);
    }

    public function chooseProvider(Nutgram $bot, string $data): void
    {
        $provider = Provider::from($data);

        if (! $provider->isAvailable()) {
            $this->setCallbackQueryOptions(['text' => 'این ارائه‌دهنده هنوز پشتیبانی نمی‌شود.', 'show_alert' => true]);
            return;
        }

        $this->provider = $provider->value;
        $this->closeMenu("دیتاسنتر: {$provider->label()}\nحالا یک نام دلخواه برای این پنل بفرستید (مثلاً: Panel-1):");
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. یک نام کوتاه‌تر بفرستید یا /cancel را بزنید:');
            return;
        }

        $this->name = $name;
        $bot->sendMessage(
            "توکن API را ارسال کنید.\n".
            "می‌توانید آن را از این آدرس بسازید:\n".
            'https://cloud.digitalocean.com/account/api/tokens'
        );
        $this->next('receiveToken');
    }

    public function receiveToken(Nutgram $bot): void
    {
        $token = trim((string) $bot->message()?->text);

        if ($token === '') {
            $bot->sendMessage('توکن نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $provider = Provider::from($this->provider);

        try {
            $account = ProviderManager::make($provider, $token)->account();
        } catch (ProviderException $e) {
            $bot->sendMessage("توکن معتبر نیست:\n{$e->getMessage()}\nدوباره ارسال کنید یا /cancel را بزنید:");
            return;
        }

        $panel = Panel::create([
            'name' => $this->name,
            'provider' => $provider,
            'api_token' => $token,
            'meta' => ['email' => $account['email'] ?? null, 'uuid' => $account['uuid'] ?? null],
            'is_active' => true,
            'created_by' => $bot->userId(),
        ]);

        // remove the token from the chat history for basic hygiene
        try {
            $bot->deleteMessage($bot->chatId(), $bot->messageId());
        } catch (\Throwable) {
        }

        $bot->sendMessage(
            "✅ پنل «{$panel->name}» با موفقیت اضافه شد.\n".
            'ایمیل اکانت: '.($account['email'] ?? '-')
        );
        $this->end();
    }
}
