<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard profile's "country" — a free-text label the admin types
 * themselves purely to recognize which profile is for which country in the
 * bot's UI. Unlike `name`, it has no technical role (no DNS/interface use),
 * so any text is accepted.
 */
class SetWireguardProfileCountryConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $profileId = null;

    public function start(Nutgram $bot, int $profileId): void
    {
        $this->profileId = $profileId;

        $bot->sendMessage(
            "نام کشور این پروفایل را بفرستید (مثلاً: آلبانی).\n".
            'برای حذف مقدار فعلی، - بفرستید.',
            reply_markup: $this->backButton()
        );
        $this->next('receiveCountry');
    }

    public function receiveCountry(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardProfileMenu::begin($bot);

            return;
        }

        $country = trim((string) $bot->message()?->text);
        $country = ($country === '' || $country === '-') ? null : $country;

        WireguardProfile::whereKey($this->profileId)->update(['country' => $country]);

        $bot->sendMessage($country === null ? '✅ کشور حذف شد.' : "✅ کشور به «{$country}» تنظیم شد.");
        $this->end();
        WireguardProfileMenu::begin($bot, $bot->userId(), $bot->chatId(), [$this->profileId]);
    }
}
