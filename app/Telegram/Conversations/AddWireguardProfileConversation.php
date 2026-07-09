<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardProfile;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\CancellableTextStep;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * A profile's name doubles as its DNS subdomain label (e.g. "germany" ->
 * germany.node.pcbot.top — see PasarguardNodeInstaller), so it's restricted
 * to valid DNS-label characters at creation time rather than silently
 * sanitized later, so what the admin sees is exactly what gets registered.
 */
class AddWireguardProfileConversation extends Conversation
{
    use Cancellable;
    use CancellableTextStep;

    protected const NAME_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/';

    protected ?string $name = null;
    protected ?string $country = null;

    public function start(Nutgram $bot): void
    {
        $bot->sendMessage('یک نام برای این پروفایل بفرستید (مثلاً: germany):', reply_markup: $this->backButton());
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

        if (! preg_match(self::NAME_PATTERN, $name)) {
            $bot->sendMessage(
                'نام نامعتبر است (فقط حروف انگلیسی، عدد و خط‌تیره مجاز است، چون این نام زیردامنه‌ی نود هم می‌شود). دوباره ارسال کنید:',
                reply_markup: $this->backButton()
            );
            return;
        }

        if (WireguardProfile::where('name', $name)->exists()) {
            $bot->sendMessage('پروفایلی با این نام از قبل وجود دارد. نام دیگری بفرستید:', reply_markup: $this->backButton());
            return;
        }

        $this->name = $name;
        $bot->sendMessage(
            "نام کشور این پروفایل را بفرستید (فقط برای تشخیص خودتان در ربات، مثلاً: آلبانی).\n".
            'برای رد شدن، - بفرستید:',
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

        $this->country = ($country === '' || $country === '-') ? null : $country;
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

        $profile = WireguardProfile::create([
            'name' => $this->name,
            'country' => $this->country,
            'private_key' => $key,
        ]);

        $this->end();
        WireguardProfileMenu::begin($bot, $bot->userId(), $bot->chatId(), [$profile->id, true]);
    }
}
