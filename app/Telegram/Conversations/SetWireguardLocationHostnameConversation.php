<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard location's "hostname" — the rotating subdomain its `ip`
 * was originally picked from. Optional: leaving it unset just means
 * LocationHealer (see CheckWireguardTunnelsJob) has nothing to re-resolve
 * and skips this location, same as a WireguardProfile with no own_ip.
 */
class SetWireguardLocationHostnameConversation extends Conversation
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

        $bot->sendMessage(
            "دامنه‌ای که آی‌پی این لوکیشن از آن گرفته می‌شود را بفرستید (مثلاً: se.example.com).\n".
            'برای حذف مقدار فعلی، یک خط تیره (-) بفرستید.',
            reply_markup: $this->backButton()
        );
        $this->next('receiveHostname');
    }

    public function receiveHostname(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardMenu::begin($bot);

            return;
        }

        $text = trim((string) $bot->message()?->text);

        if ($text === '-') {
            WireguardLocation::ownedBy($bot->userId())->whereKey($this->locationId)->update(['hostname' => null]);
            $bot->sendMessage('✅ دامنه حذف شد.');
            $this->end();
            WireguardMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->locationId]);

            return;
        }

        if (! filter_var('http://'.$text, FILTER_VALIDATE_URL) || ! str_contains($text, '.')) {
            $bot->sendMessage('دامنه نامعتبر است. دوباره ارسال کنید (یا - برای حذف):', reply_markup: $this->backButton());

            return;
        }

        WireguardLocation::ownedBy($bot->userId())->whereKey($this->locationId)->update(['hostname' => $text]);

        $bot->sendMessage("✅ دامنه به {$text} تنظیم شد.");
        $this->end();
        WireguardMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->locationId]);
    }
}
