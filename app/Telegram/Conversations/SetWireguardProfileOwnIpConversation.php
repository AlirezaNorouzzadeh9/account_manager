<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard profile's "own_ip" — the profile's real, current server
 * IP (normally set automatically on node install/replace, see
 * InstallPasarguardNodeJob/ReplaceServerFinishJob). This manual override
 * exists for profiles managed outside this bot's own create/replace flow,
 * or whose own_ip needs correcting — CheckWireguardProfileJob pings THIS,
 * not whatever the domain currently resolves to, to decide the profile's
 * real health and to know where to restore the domain once it recovers.
 */
class SetWireguardProfileOwnIpConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $profileId = null;

    public function start(Nutgram $bot, int $profileId): void
    {
        if (! WireguardProfile::ownedBy($bot->userId())->whereKey($profileId)->exists()) {
            $bot->sendMessage('⛔️ این پروفایل متعلق به شما نیست.');
            $this->end();

            return;
        }

        $this->profileId = $profileId;

        $bot->sendMessage(
            "آی‌پی اصلی سرور این پروفایل را بفرستید.\n".
            'برای حذف مقدار فعلی، یک خط تیره (-) بفرستید.',
            reply_markup: $this->backButton()
        );
        $this->next('receiveOwnIp');
    }

    public function receiveOwnIp(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardProfileMenu::begin($bot);

            return;
        }

        $text = trim((string) $bot->message()?->text);

        if ($text === '-') {
            WireguardProfile::ownedBy($bot->userId())->whereKey($this->profileId)->update(['own_ip' => null]);
            $bot->sendMessage('✅ آی‌پی اصلی حذف شد.');
            $this->end();
            WireguardProfileMenu::begin($bot);

            return;
        }

        if (! filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $bot->sendMessage('آی‌پی نامعتبر است. دوباره ارسال کنید (یا - برای حذف):', reply_markup: $this->backButton());

            return;
        }

        WireguardProfile::ownedBy($bot->userId())->whereKey($this->profileId)->update(['own_ip' => $text]);

        $bot->sendMessage("✅ آی‌پی اصلی به {$text} تنظیم شد.");
        $this->end();
        WireguardProfileMenu::begin($bot);
    }
}
