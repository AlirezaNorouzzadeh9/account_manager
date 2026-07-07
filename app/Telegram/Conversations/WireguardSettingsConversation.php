<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardSettings;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Edits the fields shared by every WireGuard location's [Interface]/[Peer]
 * (Address/DNS/AllowedIPs/Port/PrivateKey) — everything except ip/
 * server_public_key, which live on each WireguardLocation instead.
 * PrivateKey identifies this bot's WireGuard client itself, so it's the same
 * value reused across every location rather than one per location.
 */
class WireguardSettingsConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?string $address = null;
    protected ?string $dns = null;
    protected ?string $allowedIps = null;
    protected ?int $port = null;

    public function start(Nutgram $bot): void
    {
        $current = WireguardSettings::current();

        $bot->sendMessage(
            "Address را بفرستید (فعلی: {$current->address}):",
            reply_markup: $this->backButton()
        );
        $this->next('receiveAddress');
    }

    public function receiveAddress(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $value = trim((string) $bot->message()?->text);

        if ($value === '') {
            $bot->sendMessage('نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        $this->address = $value;
        $bot->sendMessage("DNS را بفرستید، یا '-' برای بدون DNS:", reply_markup: $this->backButton());
        $this->next('receiveDns');
    }

    public function receiveDns(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $value = trim((string) $bot->message()?->text);
        $this->dns = $value === '-' ? null : $value;

        $bot->sendMessage('AllowedIPs را بفرستید (مثلاً: 0.0.0.0/0):', reply_markup: $this->backButton());
        $this->next('receiveAllowedIps');
    }

    public function receiveAllowedIps(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $value = trim((string) $bot->message()?->text);

        if ($value === '') {
            $bot->sendMessage('نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        $this->allowedIps = $value;
        $bot->sendMessage('پورت Endpoint را بفرستید (مثلاً: 51820):', reply_markup: $this->backButton());
        $this->next('receivePort');
    }

    public function receivePort(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $value = trim((string) $bot->message()?->text);

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            $bot->sendMessage('پورت نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        $this->port = (int) $value;

        $current = WireguardSettings::current();
        $bot->sendMessage(
            'PrivateKey مشترک برای همه‌ی لوکیشن‌ها را بفرستید'.
            ($current->private_key ? " (فعلی: {$current->private_key})" : '').':',
            reply_markup: $this->backButton()
        );
        $this->next('receivePrivateKey');
    }

    public function receivePrivateKey(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);
            return;
        }

        $value = trim((string) $bot->message()?->text);

        if ($value === '') {
            $bot->sendMessage('نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        WireguardSettings::current()->update([
            'address' => $this->address,
            'dns' => $this->dns,
            'allowed_ips' => $this->allowedIps,
            'port' => $this->port,
            'private_key' => $value,
        ]);

        $bot->sendMessage('✅ تنظیمات پایه‌ی وایرگارد ذخیره شد. برای اعمال روی سرورهای موجود، «بروزرسانی وایرگارد» را دوباره بزنید.');
        $this->end();
        WireguardMenu::begin($bot);
    }
}
