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
    protected ?string $ovhApplicationKey = null;
    protected ?string $ovhApplicationSecret = null;
    protected ?string $ovhConsumerKey = null;
    protected ?string $ovhServiceName = null;
    protected ?string $ovhProjectName = null;

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

        if (Provider::from($this->provider) === Provider::Ovh) {
            $bot->sendMessage(
                "برای OVH باید از https://eu.api.ovh.com/createApp/ یک Application (Key+Secret) بسازید، سپس یک Consumer Key تأییدشده برایش بگیرید (مرحله تأیید در مرورگر انجام می‌شود، خارج از این ربات).\n\n".
                'Application Key را ارسال کنید:',
                reply_markup: $this->backButton()
            );
            $this->next('receiveOvhApplicationKey');

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

    protected function receiveCredentialField(Nutgram $bot, string $prompt): ?string
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
        $value = $this->receiveCredentialField($bot, 'دوباره Tenant ID را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->tenantId = $value;
        $bot->sendMessage('Client ID (Application ID اپلیکیشن ثبت‌شده) را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveClientId');
    }

    public function receiveClientId(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره Client ID را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->clientId = $value;
        $bot->sendMessage('Client Secret (از Certificates & secrets) را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveClientSecret');
    }

    public function receiveClientSecret(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره Client Secret را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->clientSecret = $value;
        $bot->sendMessage('Subscription ID را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveSubscriptionId');
    }

    public function receiveSubscriptionId(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره Subscription ID را بفرستید:');

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
        $value = $this->receiveCredentialField($bot, 'دوباره نام Resource Group را بفرستید:');

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

    public function receiveOvhApplicationKey(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره Application Key را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->ovhApplicationKey = $value;
        $bot->sendMessage('Application Secret را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveOvhApplicationSecret');
    }

    public function receiveOvhApplicationSecret(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره Application Secret را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->ovhApplicationSecret = $value;
        $bot->sendMessage('Consumer Key تأییدشده را ارسال کنید:', reply_markup: $this->backButton());
        $this->next('receiveOvhConsumerKey');
    }

    public function receiveOvhConsumerKey(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره Consumer Key را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->ovhConsumerKey = $value;
        $bot->sendMessage(
            'نام Cloud Project (Service Name) را ارسال کنید (از https://www.ovh.com/manager/#/public-cloud):',
            reply_markup: $this->backButton()
        );
        $this->next('receiveOvhServiceName');
    }

    public function receiveOvhServiceName(Nutgram $bot): void
    {
        $value = $this->receiveCredentialField($bot, 'دوباره نام Cloud Project را بفرستید:');

        if ($value === null) {
            return;
        }

        $this->ovhServiceName = $value;

        try {
            $account = ProviderManager::make(Provider::Ovh, $this->ovhConsumerKey, [
                'application_key' => $this->ovhApplicationKey,
                'application_secret' => $this->ovhApplicationSecret,
                'service_name' => $this->ovhServiceName,
            ])->account();
            $this->ovhProjectName = $account['email'] ?? $this->ovhServiceName;
        } catch (ProviderException $e) {
            $this->ovhApplicationKey = $this->ovhApplicationSecret = $this->ovhConsumerKey = $this->ovhServiceName = $this->ovhProjectName = null;
            $bot->sendMessage(
                "اطلاعات وارد شده معتبر نیست:\n{$e->getMessage()}\nدوباره از Application Key شروع کنید:",
                reply_markup: $this->backButton()
            );
            $this->next('receiveOvhApplicationKey');

            return;
        }

        $panel = Panel::create([
            'name' => $this->name,
            'provider' => Provider::Ovh,
            'api_token' => $this->ovhConsumerKey,
            'meta' => [
                'email' => $this->ovhProjectName,
                'uuid' => $this->ovhServiceName,
                'application_key' => $this->ovhApplicationKey,
                'application_secret' => $this->ovhApplicationSecret,
                'service_name' => $this->ovhServiceName,
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
