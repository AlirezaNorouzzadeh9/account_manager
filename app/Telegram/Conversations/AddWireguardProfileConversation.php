<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class AddWireguardProfileConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected ?string $name = null;

    public function start(Nutgram $bot): void
    {
        $bot->sendMessage('یک نام برای این پروفایل بفرستید (مثلاً: server-1):', reply_markup: $this->backButton());
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        if ($this->backTapped($bot)) {
            $this->end();
            WireguardProfileMenu::begin($bot);
            return;
        }

        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. دوباره ارسال کنید:', reply_markup: $this->backButton());
            return;
        }

        if (WireguardProfile::where('name', $name)->exists()) {
            $bot->sendMessage('پروفایلی با این نام از قبل وجود دارد. نام دیگری بفرستید:', reply_markup: $this->backButton());
            return;
        }

        $this->name = $name;
        $bot->sendMessage('PrivateKey این پروفایل را بفرستید:', reply_markup: $this->backButton());
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

        WireguardProfile::create([
            'name' => $this->name,
            'private_key' => $key,
        ]);

        $bot->sendMessage("✅ پروفایل «{$this->name}» ذخیره شد.");
        $this->end();
        WireguardProfileMenu::begin($bot);
    }
}
