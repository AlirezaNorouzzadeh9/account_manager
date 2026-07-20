<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard location's "ip" — the upstream relay server's own
 * address, used as the [Peer] Endpoint for every node that has WireGuard
 * enabled (see PasarguardNodeInstaller::buildLocationConfig()). Unlike
 * country/own_ip, this has no "clear" option — a location's Endpoint can't
 * be blank, so a new value always replaces the old one.
 */
class SetWireguardLocationIpConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $locationId = null;

    public function start(Nutgram $bot, int $locationId): void
    {
        if (! WireguardLocation::ownedBy($bot->userId())->whereKey($locationId)->exists()) {
            $bot->sendMessage('⛔️ این لوکیشن متعلق به شما نیست.');
            $this->end();

            return;
        }

        $this->locationId = $locationId;

        $bot->sendMessage('آی‌پی جدید این لوکیشن را بفرستید:', reply_markup: $this->backButton());
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

        WireguardLocation::ownedBy($bot->userId())->whereKey($this->locationId)->update(['ip' => $ip]);

        $bot->sendMessage("✅ آی‌پی به {$ip} تنظیم شد.");
        $this->end();
        WireguardMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->locationId]);
    }
}
