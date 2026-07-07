<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardSettings;
use App\Telegram\Support\Cancellable;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Edits the fields shared by every WireGuard location's [Interface]/[Peer]
 * (Address/DNS/AllowedIPs/Port/Table) — everything except ip/server_public_key/
 * private_key, which live on each WireguardLocation instead.
 */
class WireguardSettingsConversation extends Conversation
{
    use Cancellable;

    protected ?string $address = null;
    protected ?string $dns = null;
    protected ?string $allowedIps = null;
    protected ?int $port = null;

    public function start(Nutgram $bot): void
    {
        $current = WireguardSettings::current();

        $bot->sendMessage(
            "Address را بفرستید (فعلی: {$current->address}):"
        );
        $this->next('receiveAddress');
    }

    public function receiveAddress(Nutgram $bot): void
    {
        $value = trim((string) $bot->message()?->text);

        if ($value === '') {
            $bot->sendMessage('نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->address = $value;
        $bot->sendMessage("DNS را بفرستید، یا '-' برای بدون DNS:");
        $this->next('receiveDns');
    }

    public function receiveDns(Nutgram $bot): void
    {
        $value = trim((string) $bot->message()?->text);
        $this->dns = $value === '-' ? null : $value;

        $bot->sendMessage('AllowedIPs را بفرستید (مثلاً: 0.0.0.0/0):');
        $this->next('receiveAllowedIps');
    }

    public function receiveAllowedIps(Nutgram $bot): void
    {
        $value = trim((string) $bot->message()?->text);

        if ($value === '') {
            $bot->sendMessage('نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->allowedIps = $value;
        $bot->sendMessage('پورت Endpoint را بفرستید (مثلاً: 51820):');
        $this->next('receivePort');
    }

    public function receivePort(Nutgram $bot): void
    {
        $value = trim((string) $bot->message()?->text);

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            $bot->sendMessage('پورت نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->port = (int) $value;

        WireguardSettings::current()->update([
            'address' => $this->address,
            'dns' => $this->dns,
            'allowed_ips' => $this->allowedIps,
            'port' => $this->port,
        ]);

        $bot->sendMessage('✅ تنظیمات پایه‌ی وایرگارد ذخیره شد. برای اعمال روی سرورهای موجود، «بروزرسانی وایرگارد» را دوباره بزنید.');
        $this->end();
    }
}
