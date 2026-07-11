<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class AddWireguardConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?string $name = null;
    protected ?string $country = null;
    protected ?string $ip = null;

    public function start(Nutgram $bot): void
    {
        $bot->sendMessage('یک نام برای این لوکیشن بفرستید (مثلاً: germany):', reply_markup: $this->backButton());
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        if (WireguardLocation::ownedBy($bot->userId())->where('name', $name)->exists()) {
            $bot->sendMessage('لوکیشنی با این نام از قبل وجود دارد. نام دیگری بفرستید:', reply_markup: $this->backButton());
            return;
        }

        $this->name = $name;
        $bot->sendMessage(
            "کد دو حرفی کشور این لوکیشن را بفرستید تا پرچمش نشان داده شود (مثلاً: DE برای آلمان، NL برای هلند، IT برای ایتالیا).\n".
            'برای رد شدن، - بفرستید:',
            reply_markup: $this->backButton()
        );
        $this->next('receiveCountry');
    }

    public function receiveCountry(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $country = trim((string) $bot->message()?->text);

        if ($country === '' || $country === '-') {
            $this->country = null;
        } elseif (preg_match('/^[A-Za-z]{2}$/', $country)) {
            $this->country = strtoupper($country);
        } else {
            $bot->sendMessage(
                'کد کشور نامعتبر است. یک کد دو حرفی بفرستید (مثلاً: DE)، یا برای رد شدن - بفرستید:',
                reply_markup: $this->backButton()
            );
            return;
        }

        $bot->sendMessage('آی‌پی این لوکیشن را بفرستید:', reply_markup: $this->backButton());
        $this->next('receiveIp');
    }

    public function receiveIp(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $ip = trim((string) $bot->message()?->text);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $bot->sendMessage('آی‌پی نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        $this->ip = $ip;
        $bot->sendMessage('PublicKey سرور (لوکیشن) را بفرستید:', reply_markup: $this->backButton());
        $this->next('receiveServerPublicKey');
    }

    public function receiveServerPublicKey(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $key = trim((string) $bot->message()?->text);

        if ($key === '') {
            $bot->sendMessage('PublicKey نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        $location = WireguardLocation::create([
            'name' => $this->name,
            'country' => $this->country,
            'ip' => $this->ip,
            'server_public_key' => $key,
            'created_by' => $bot->userId(),
        ]);

        $this->end();
        WireguardMenu::begin($bot, $bot->userId(), $bot->chatId(), [$location->id, true]);
    }
}
