<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * Edits an existing profile's PrivateKey — previously only settable once, at
 * creation time (AddWireguardProfileConversation). Lets an admin create a
 * placeholder profile up front (e.g. to reserve a name/slot) and fill in the
 * real key afterward, or rotate a compromised key without deleting and
 * recreating the profile.
 */
class SetWireguardProfilePrivateKeyConversation extends Conversation
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

        $bot->sendMessage('PrivateKey جدید این پروفایل را بفرستید:', reply_markup: $this->backButton());
        $this->next('receivePrivateKey');
    }

    public function receivePrivateKey(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardProfileMenu::begin($bot);

            return;
        }

        $key = trim((string) $bot->message()?->text);

        if ($key === '') {
            $bot->sendMessage('PrivateKey نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());

            return;
        }

        // A hydrated model + ->update(), NOT a query-builder mass update —
        // the latter bypasses the 'encrypted' cast entirely and would
        // silently persist the key in plaintext (see ServerListMenu's
        // root_password fix for the same class of bug).
        WireguardProfile::ownedBy($bot->userId())->whereKey($this->profileId)->first()?->update(['private_key' => $key]);

        $bot->sendMessage('✅ PrivateKey بروزرسانی شد.');
        $this->end();
        WireguardProfileMenu::begin($bot);
    }
}
