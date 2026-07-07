<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use App\Services\Providers\ProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

class UpdateWireguardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;
    public int $timeout = 180;

    public function __construct(
        protected int $panelId,
        protected int|string $serverId,
        protected int $chatId,
    ) {
    }

    public function handle(Nutgram $bot, PasarguardNodeInstaller $installer): void
    {
        $panel = Panel::find($this->panelId);
        $secret = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->first();

        if (! $panel || ! $secret) {
            $bot->sendMessage('❌ رمز روت این سرور ذخیره نشده، امکان بروزرسانی خودکار نیست.', chat_id: $this->chatId);

            return;
        }

        try {
            $server = ProviderManager::forPanel($panel)->getServer($this->serverId);
        } catch (Throwable $e) {
            $bot->sendMessage("❌ خطا در دریافت اطلاعات سرور:\n{$e->getMessage()}", chat_id: $this->chatId);

            return;
        }

        $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null;

        if (! $ip) {
            $this->release(10);

            return;
        }

        try {
            $result = $installer->updateWireguards($ip, 'root', $secret->root_password, $secret->wireguardProfile?->private_key);
        } catch (RuntimeException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(15);

                return;
            }

            $bot->sendMessage(
                "❌ اتصال به سرور ناموفق بود:\n{$e->getMessage()}\nاحتمالاً پسورد روت عوض شده.",
                chat_id: $this->chatId,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(
                        '🔁 وارد کردن پسورد جدید',
                        callback_data: "retry_wg_pw:{$this->panelId}:{$this->serverId}"
                    )
                ),
            );

            return;
        } catch (Throwable $e) {
            $bot->sendMessage("❌ بروزرسانی وایرگاردها ناموفق بود:\n{$e->getMessage()}", chat_id: $this->chatId);

            return;
        }

        $message = ($result['success'] ? '✅ ' : '❌ ').$result['message'];

        if (! $result['success'] && $result['log'] !== '') {
            $message .= "\n\n".mb_substr($result['log'], -1500);
        }

        $bot->sendMessage($message, chat_id: $this->chatId);
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            '❌ بروزرسانی وایرگاردها با خطای غیرمنتظره متوقف شد.',
            chat_id: $this->chatId,
        );
    }
}
