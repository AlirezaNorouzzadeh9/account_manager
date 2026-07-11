<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use App\Services\Pasarguard\PasarguardPanelClient;
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

        $profile = $secret->wireguardProfile;

        try {
            // No profile name passed: this node always gets its own per-IP
            // certificate and is always registered in the panel by IP, never
            // by a DNS-backed domain (see PasarguardNodeInstaller — a domain
            // cert's SAN only covers that domain, so registering it under a
            // plain IP would fail the panel's own certificate check).
            $result = $installer->install($ip, 'root', $secret->root_password, $profile?->private_key);
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

        if ($result['success'] && $profile && ! empty($result['cert'])) {
            $message .= "\n\n".$this->registerNode($profile, $ip, $result['cert']);
        } elseif (! empty($result['cert'])) {
            $message .= "\n\nگواهی SSL این نود (برای ثبت در پنل PasarGuard):\n{$result['cert']}";
        }

        $bot->sendMessage($message, chat_id: $this->chatId);
    }

    /**
     * Auto-registers a fresh PasarGuard panel node for this profile (every
     * install replaces whatever node the profile pointed at before — this
     * bot tracks exactly one live node per profile, not several), falling
     * back to printing the cert for manual registration when the panel
     * isn't configured or the API call fails.
     */
    protected function registerNode(WireguardProfile $profile, string $address, string $cert): string
    {
        if (! filled(config('pasarguard.panel.url')) || ! filled(config('pasarguard.panel.username')) || ! filled(config('pasarguard.panel.password'))) {
            return "⚠️ اطلاعات پنل PasarGuard تنظیم نشده؛ نود را دستی با این گواهی در پنل ثبت کنید:\n{$cert}";
        }

        $client = new PasarguardPanelClient(
            config('pasarguard.panel.url'),
            config('pasarguard.panel.username'),
            config('pasarguard.panel.password'),
        );

        $coreConfigId = 1;

        if ($profile->core_id) {
            try {
                $oldNode = $client->getNode($profile->core_id);
                $coreConfigId = (int) ($oldNode['core_config_id'] ?? 1);
            } catch (Throwable) {
                // best-effort continuity only — the default core config id is fine
            }
        }

        try {
            $newNodeId = $client->createNode([
                'name' => "{$profile->name} ({$address})",
                'address' => $address,
                'port' => PasarguardNodeInstaller::NODE_PORT,
                'api_port' => PasarguardNodeInstaller::NODE_API_PORT,
                'connection_type' => 'grpc',
                'server_ca' => $cert,
                'keep_alive' => 60,
                'core_config_id' => $coreConfigId,
                'api_key' => config('pasarguard.api_key'),
            ]);

            $profile->update(['core_id' => $newNodeId]);

            return "✅ نود جدید (id={$newNodeId}) در پنل PasarGuard ثبت شد.";
        } catch (Throwable $e) {
            return "⚠️ ثبت خودکار نود در پنل ناموفق بود ({$e->getMessage()})؛ دستی با این گواهی ثبت کنید:\n{$cert}";
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
