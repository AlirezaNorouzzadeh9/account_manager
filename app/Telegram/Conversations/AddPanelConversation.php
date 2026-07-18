<?php

namespace App\Telegram\Conversations;

use App\Enums\Provider;
use App\Models\Panel;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Telegram\Support\CancellableTextStep;
use App\Telegram\Support\EditsInPlace;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class AddPanelConversation extends InlineMenu
{
    use CancellableTextStep;
    use EditsInPlace;

    protected ?string $provider = null;
    protected ?string $name = null;
    protected ?string $tenantId = null;
    protected ?string $clientId = null;
    protected ?string $clientSecret = null;
    protected ?string $subscriptionId = null;
    protected ?string $subscriptionName = null;

    public function start(Nutgram $bot): void
    {
        $this->editInPlaceFromCallback($bot);
        $this->clearButtons();
        $this->menuText('کدام دیتاسنتر را می‌خواهید اضافه کنید؟');

        foreach (Provider::cases() as $provider) {
            $label = '☁️ '.$provider->label().($provider->isAvailable() ? '' : ' (به‌زودی)');
            $this->addButtonRow(InlineKeyboardButton::make($label, callback_data: "{$provider->value}@chooseProvider"));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToPanels'));
        $this->showMenu();
    }

    public function backToPanels(Nutgram $bot): void
    {
        $this->endWithoutClosing();
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
        $this->closeMenu(
            "دیتاسنتر: {$provider->label()}\nحالا یک نام دلخواه برای این پنل بفرستید (مثلاً: Panel-1):",
            ['reply_markup' => $this->backButton()]
        );
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            PanelsMenu::begin($bot);
            return;
        }

        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. یک نام کوتاه‌تر بفرستید:', reply_markup: $this->backButton());
            return;
        }

        $this->name = $name;

        if (Provider::from($this->provider) === Provider::Azure) {
            $bot->sendMessage(
                "برای Azure باید یک App Registration در Microsoft Entra ID بسازید و نقش Contributor روی Subscription (یا Resource Group) بدهید.\n\n".
                'Tenant ID را ارسال کنید (از Entra ID → Overview):',
                reply_markup: $this->backButton()
            );
            $this->next('receiveTenantId');

            return;
        }

        $tokenUrl = match (Provider::from($this->provider)) {
            Provider::Linode => 'https://cloud.linode.com/profile/tokens',
            Provider::Vultr => 'https://my.vultr.com/settings/#settingsapi',
            default => 'https://cloud.digitalocean.com/account/api/tokens',
        };

        $bot->sendMessage(
            "توکن API را ارسال کنید.\n".
            "می‌توانید آن را از این آدرس بسازید:\n".
            $tokenUrl,
            reply_markup: $this->backButton()
        );
        $this->next('receiveToken');
    }

    protected function receiveAzureField(Nutgram $bot, string $prompt): ?string
    {
        if ($this->backTapped($bot)) {
            $this->end();
            PanelsMenu::begin($bot);

            return null;
        }

        $value = trim((string) $bot->message()?->text);

        if ($value === '') {
            $bot->sendMessage("مقدار نامعتبر است. {$prompt}", reply_markup: $this->backButton());

            return null;
        }

        return $value;
    }

    public function receiveTenantId(Nutgram $bot): void
    {
        $value = $this->receiveAzureField($bot, 'دوباره Tenant ID را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->tenantId = $value;
        $bot->sendMessage('Client ID (Application ID اپلیکیشن ثبت‌شده) را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveClientId');
    }

    public function receiveClientId(Nutgram $bot): void
    {
        $value = $this->receiveAzureField($bot, 'دوباره Client ID را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->clientId = $value;
        $bot->sendMessage('Client Secret (از Certificates & secrets) را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveClientSecret');
    }

    public function receiveClientSecret(Nutgram $bot): void
    {
        $value = $this->receiveAzureField($bot, 'دوباره Client Secret را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->clientSecret = $value;
        $bot->sendMessage('Subscription ID را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveSubscriptionId');
    }

    public function receiveSubscriptionId(Nutgram $bot): void
    {
        $value = $this->receiveAzureField($bot, 'دوباره Subscription ID را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->subscriptionId = $value;

        try {
            $account = ProviderManager::make(Provider::Azure, $this->clientSecret, [
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'subscription_id' => $this->subscriptionId,
            ])->account();
            $this->subscriptionName = $account['email'] ?? $this->subscriptionId;
        } catch (ProviderException $e) {
            $this->tenantId = $this->clientId = $this->clientSecret = $this->subscriptionId = $this->subscriptionName = null;
            $bot->sendMessage(
                "اطلاعات وارد شده معتبر نیست:\n{$e->getMessage()}\nدوباره از Tenant ID شروع کنید:",
                reply_markup: $this->backButton()
            );
            $this->next('receiveTenantId');

            return;
        }

        // Not live-validated here — createServer() auto-creates it on first
        // use if it doesn't already exist in Azure.
        $bot->sendMessage(
            "اطلاعات معتبر بود.\nنام Resource Group را ارسال کنید (اگر وجود نداشته باشد، خودکار ساخته می‌شود):",
            reply_markup: $this->backButton()
        );
        $this->next('receiveResourceGroup');
    }

    public function receiveResourceGroup(Nutgram $bot): void
    {
        $value = $this->receiveAzureField($bot, 'دوباره نام Resource Group را بفرستید:');

        if ($value === null) {
            return;
        }

        $panel = Panel::create([
            'name' => $this->name,
            'provider' => Provider::Azure,
            'api_token' => $this->clientSecret,
            'meta' => [
                'email' => $this->subscriptionName,
                'uuid' => $this->subscriptionId,
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'subscription_id' => $this->subscriptionId,
                'resource_group' => $value,
            ],
            'is_active' => true,
            'created_by' => $bot->userId(),
        ]);

        try {
            $bot->deleteMessage($bot->chatId(), $bot->messageId());
        } catch (\Throwable) {
        }

        $this->end();
        PanelsMenu::begin($bot, $bot->userId(), $bot->chatId(), [$panel->id, true]);
    }

    public function receiveToken(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            PanelsMenu::begin($bot);
            return;
        }

        $token = trim((string) $bot->message()?->text);

        if ($token === '') {
            $bot->sendMessage('توکن نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        $provider = Provider::from($this->provider);

        try {
            $account = ProviderManager::make($provider, $token)->account();
        } catch (ProviderException $e) {
            $bot->sendMessage("توکن معتبر نیست:\n{$e->getMessage()}\nدوباره ارسال کنید:", reply_markup: $this->backButton());
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

        $this->end();
        PanelsMenu::begin($bot, $bot->userId(), $bot->chatId(), [$panel->id, true]);
    }
}
