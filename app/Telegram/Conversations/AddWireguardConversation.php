<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\Cancellable;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class AddWireguardConversation extends Conversation
{
    use Cancellable;

    protected ?string $name = null;
    protected ?string $ip = null;
    protected ?string $serverPublicKey = null;

    public function start(Nutgram $bot): void
    {
        $bot->sendMessage('یک نام برای این لوکیشن بفرستید (مثلاً: germany) یا /cancel را بزنید:');
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        if (WireguardLocation::where('name', $name)->exists()) {
            $bot->sendMessage('لوکیشنی با این نام از قبل وجود دارد. نام دیگری بفرستید یا /cancel را بزنید:');
            return;
        }

        $this->name = $name;
        $bot->sendMessage('آی‌پی این لوکیشن را بفرستید:');
        $this->next('receiveIp');
    }

    public function receiveIp(Nutgram $bot): void
    {
        $ip = trim((string) $bot->message()?->text);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $bot->sendMessage('آی‌پی نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->ip = $ip;
        $bot->sendMessage('PublicKey سرور (لوکیشن) را بفرستید:');
        $this->next('receiveServerPublicKey');
    }

    public function receiveServerPublicKey(Nutgram $bot): void
    {
        $key = trim((string) $bot->message()?->text);

        if ($key === '') {
            $bot->sendMessage('PublicKey نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->serverPublicKey = $key;
        $bot->sendMessage('PrivateKey مخصوص همین لوکیشن را بفرستید:');
        $this->next('receivePrivateKey');
    }

    public function receivePrivateKey(Nutgram $bot): void
    {
        $key = trim((string) $bot->message()?->text);

        if ($key === '') {
            $bot->sendMessage('PrivateKey نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        WireguardLocation::create([
            'name' => $this->name,
            'ip' => $this->ip,
            'server_public_key' => $this->serverPublicKey,
            'private_key' => $key,
        ]);

        $bot->sendMessage("✅ لوکیشن «{$this->name}» ذخیره شد و روی هر سروری که وایرگارد فعال شود اعمال می‌شود.");
        $this->end();
    }
}
