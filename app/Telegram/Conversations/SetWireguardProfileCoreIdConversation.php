<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Sets a WireGuard profile's "core_id" — the id of this profile's node in
 * the PasarGuard PANEL's own API (GET /api/node/{id}) — so a domain-backed
 * IP change can auto-reconnect that node via PasarguardPanelClient instead
 * of relying on the admin to reset it manually every time.
 */
class SetWireguardProfileCoreIdConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?int $profileId = null;

    public function start(Nutgram $bot, int $profileId): void
    {
        $this->profileId = $profileId;

        $bot->sendMessage(
            "core_id این پروفایل در پنل PasarGuard را بفرستید (همان id نود این پروفایل در پنل).\n".
            'برای حذف مقدار فعلی، عدد 0 بفرستید.',
            reply_markup: $this->backButton()
        );
        $this->next('receiveCoreId');
    }

    public function receiveCoreId(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardProfileMenu::begin($bot);

            return;
        }

        $text = trim((string) $bot->message()?->text);

        if (! ctype_digit($text)) {
            $bot->sendMessage('عدد نامعتبر است. یک عدد صحیح بفرستید (یا 0 برای حذف):', reply_markup: $this->backButton());

            return;
        }

        $coreId = (int) $text;

        WireguardProfile::whereKey($this->profileId)->update(['core_id' => $coreId === 0 ? null : $coreId]);

        $bot->sendMessage($coreId === 0 ? '✅ core_id حذف شد.' : "✅ core_id به {$coreId} تنظیم شد.");
        $this->end();
        WireguardProfileMenu::begin($bot);
    }
}
