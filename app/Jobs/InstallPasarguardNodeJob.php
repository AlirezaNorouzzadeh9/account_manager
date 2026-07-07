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

class InstallPasarguardNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;
    public int $timeout = 300;

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
            $bot->sendMessage(
                '❌ رمز روت این سرور ذخیره نشده (احتمالاً قبل از اضافه شدن این قابلیت ساخته شده)، امکان نصب خودکار نیست.',
                chat_id: $this->chatId,
            );

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
            $result = $installer->install($ip, 'root', $secret->root_password, $secret->wireguardProfile?->private_key, $secret->hostname);
        } catch (RuntimeException $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(15);

                return;
            }

            $bot->sendMessage(
                "❌ اتصال به سرور برای نصب نود ناموفق بود:\n{$e->getMessage()}\nاحتمالاً پسورد روت عوض شده.",
                chat_id: $this->chatId,
                reply_markup: InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make(
                        '🔁 وارد کردن پسورد جدید',
                        callback_data: "retry_node_pw:{$this->panelId}:{$this->serverId}"
                    )
                ),
            );

            return;
        } catch (Throwable $e) {
            $bot->sendMessage("❌ نصب نود ناموفق بود:\n{$e->getMessage()}", chat_id: $this->chatId);

            return;
        }

        $message = ($result['success'] ? '✅ ' : '❌ ').$result['message'];

        if (! $result['success'] && $result['log'] !== '') {
            $message .= "\n\n".$this->condenseLog($result['log']);
        }

        // Shown regardless of overall success — DNS is best-effort and
        // silently falling back to the per-IP cert without saying why made
        // this impossible to debug from the chat alone.
        if (! empty($result['dns_warning'])) {
            $message .= "\n\n⚠️ {$result['dns_warning']}";
        }

        if (! empty($result['cert'])) {
            $message .= "\n\nگواهی SSL این نود (برای ثبت در پنل PasarGuard):\n{$result['cert']}";
        }

        $bot->sendMessage($message, chat_id: $this->chatId);

        // Sent separately (and only this part gets Markdown) so a stray
        // '_'/'*' inside the raw log or cert above can never break parsing.
        if (! empty($result['domain'])) {
            $bot->sendMessage(
                "🌐 برای ثبت در پنل PasarGuard، به‌جای آی‌پی از این آدرس استفاده کنید:\n`{$result['domain']}`",
                chat_id: $this->chatId,
                parse_mode: 'Markdown',
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            '❌ نصب نود پاسارگارد با خطای غیرمنتظره متوقف شد.',
            chat_id: $this->chatId,
        );
    }

    /**
     * Strips openssl's dot/plus progress-meter noise and keeps both the start
     * (where install errors usually show up) and the end of a long log,
     * instead of just the tail, so the actual root cause stays visible.
     */
    protected function condenseLog(string $log): string
    {
        $log = preg_replace('/^[.+*]+$/m', '', $log);
        $log = trim(preg_replace("/\n{3,}/", "\n\n", $log));

        $limit = 1800;

        if (mb_strlen($log) <= $limit) {
            return $log;
        }

        return mb_substr($log, 0, 900)."\n...[بریده شد]...\n".mb_substr($log, -900);
    }
}
