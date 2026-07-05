<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Providers\ProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Waits for a just-created droplet to finish provisioning, reports its IP +
 * root credentials, then (best-effort) kicks off an Iran-latency ping check
 * for it via check-host.net.
 */
class CreateServerReadyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 60;

    public function __construct(
        protected int $panelId,
        protected int|string $actionId,
        protected int $chatId,
        protected string $hostname,
        protected string $credentials,
    ) {
    }

    public function handle(Nutgram $bot, CheckHostClient $checkHost): void
    {
        $panel = Panel::find($this->panelId);

        if (! $panel) {
            return;
        }

        $client = ProviderManager::forPanel($panel);
        $action = $client->getAction($this->actionId);
        $status = $action['status'] ?? 'in-progress';

        if ($status === 'in-progress') {
            $this->release(10);

            return;
        }

        if ($status !== 'completed') {
            $bot->sendMessage("❌ ساخت سرور «{$this->hostname}» ناموفق بود.", chat_id: $this->chatId);

            return;
        }

        $serverId = $action['resource_id'] ?? null;
        $ip = null;

        if ($serverId) {
            try {
                $server = $client->getServer($serverId);
                $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null;
            } catch (Throwable) {
                // best-effort; the "view server" button still lets them check manually
            }
        }

        // Try to fold the check-host.net Iran ping result into the SAME final
        // message; only fall back to sending without it if the ping request
        // itself couldn't even be started (e.g. check-host.net unreachable).
        if ($ip) {
            try {
                $requestId = $checkHost->requestPing($ip);

                CreateServerFinalReportJob::dispatch(
                    $requestId,
                    $ip,
                    $this->hostname,
                    $this->credentials,
                    $this->chatId,
                    $this->panelId,
                    $serverId,
                );

                return;
            } catch (Throwable) {
                // ping check is a bonus feature, never fail server creation over it
            }
        }

        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.";

        if ($ip) {
            $message .= "\n🌐 آی‌پی: {$ip}";
        }

        $message .= "\n\n{$this->credentials}";

        $keyboard = $serverId
            ? InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$serverId}")
            )
            : null;

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard);
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "⏳ زمان بررسی وضعیت ساخت سرور «{$this->hostname}» به پایان رسید. وضعیت را از داخل پنل سرورها بررسی کنید.",
            chat_id: $this->chatId,
        );
    }
}
