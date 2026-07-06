<?php

namespace App\Telegram\Conversations;

use App\Models\WireguardConfig;
use App\Telegram\Support\Cancellable;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

class AddWireguardConversation extends Conversation
{
    use Cancellable;

    protected ?int $profileId = null;
    protected ?string $name = null;

    public function start(Nutgram $bot, int $profileId): void
    {
        $this->profileId = $profileId;
        $bot->sendMessage('یک نام برای این کانفیگ وایرگارد بفرستید (مثلاً: eu1) یا /cancel را بزنید:');
        $this->next('receiveName');
    }

    public function receiveName(Nutgram $bot): void
    {
        $name = trim((string) $bot->message()?->text);

        if ($name === '' || mb_strlen($name) > 50) {
            $bot->sendMessage('نام نامعتبر است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->name = $name;
        $bot->sendMessage('حالا کل محتوای فایل کانفیگ وایرگارد (شامل [Interface] و [Peer]) را ارسال کنید:');
        $this->next('receiveConfig');
    }

    public function receiveConfig(Nutgram $bot): void
    {
        $config = trim((string) $bot->message()?->text);

        if (! str_contains($config, '[Interface]') || ! str_contains($config, '[Peer]')) {
            $bot->sendMessage(
                'این یک کانفیگ معتبر وایرگارد به نظر نمی‌رسد (باید شامل [Interface] و [Peer] باشد). '.
                'دوباره ارسال کنید یا /cancel را بزنید:'
            );
            return;
        }

        WireguardConfig::create([
            'name' => $this->name,
            'config' => $config,
            'wireguard_profile_id' => $this->profileId,
        ]);

        $bot->sendMessage("✅ کانفیگ «{$this->name}» ذخیره شد.\nموقع نود کردن هر سرور، این کانفیگ هم روی آن فعال می‌شود.");
        $this->end();
    }
}
