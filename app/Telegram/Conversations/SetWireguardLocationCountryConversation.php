<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardLocation;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard location's "country" — a 2-letter ISO code (e.g. "AL")
 * the admin types themselves, used purely to show a flag emoji for that
 * location in the bot's UI (see WireguardLocation::flag()). Unlike `name`,
 * it has no technical role (name doubles as the interface/config filename
 * on the node), so it's validated for format only, not against a real list
 * of countries.
 */
class SetWireguardLocationCountryConversation extends Conversation
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
            "کد دو حرفی کشور این لوکیشن را بفرستید (مثلاً: DE برای آلمان).\n".
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

        if ($country === '' || $country === '-') {
            $country = null;
        } elseif (preg_match('/^[A-Za-z]{2}$/', $country)) {
            $country = strtoupper($country);
        } else {
            $bot->sendMessage(
                'کد کشور نامعتبر است. یک کد دو حرفی بفرستید (مثلاً: DE)، یا برای حذف مقدار فعلی - بفرستید:',
                reply_markup: $this->backButton()
            );

            return;
        }

        WireguardLocation::ownedBy($bot->userId())->whereKey($this->locationId)->update(['country' => $country]);

        $bot->sendMessage($country === null ? '✅ کشور حذف شد.' : "✅ کشور به «{$country}» تنظیم شد.");
        $this->end();
        WireguardMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->locationId]);
    }
}
