<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard location's "country" — a free-text label the admin types
 * themselves purely to recognize which location (e.g. "al") is for which
 * country in the bot's UI. Unlike `name`, it has no technical role (name
 * doubles as the interface/config filename on the node), so any text is
 * accepted.
 */
class SetWireguardLocationCountryConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $locationId = null;

    public function start(Nutgram $bot, int $locationId): void
    {
        $this->locationId = $locationId;

        $bot->sendMessage(
            "نام کشور این لوکیشن را بفرستید (مثلاً: آلبانی).\n".
            'برای حذف مقدار فعلی، - بفرستید.',
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
        $country = ($country === '' || $country === '-') ? null : $country;

        WireguardLocation::whereKey($this->locationId)->update(['country' => $country]);

        $bot->sendMessage($country === null ? '✅ کشور حذف شد.' : "✅ کشور به «{$country}» تنظیم شد.");
        $this->end();
        WireguardMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->locationId]);
    }
}
