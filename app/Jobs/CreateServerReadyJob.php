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
        protected ?string $region = null,
        protected ?string $size = null,
        protected ?string $image = null,
        protected int $attempt = 1,
        // The best candidate found so far across earlier attempts (see
        // CreateServerFinalReportJob's "keep the best of two" retry logic) —
        // null on the very first attempt, when there's nothing to compare yet.
        protected int|string|null $bestServerId = null,
        protected ?string $bestIp = null,
        protected ?string $bestCredentials = null,
        protected ?int $bestOkCount = null,
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
            $this->reportBestOrFail($bot, "❌ ساخت سرور «{$this->hostname}» (تلاش {$this->attempt}) ناموفق بود.");

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
                    $this->region,
                    $this->size,
                    $this->image,
                    $this->attempt,
                    $this->bestServerId,
                    $this->bestIp,
                    $this->bestCredentials,
                    $this->bestOkCount,
                );

                return;
            } catch (Throwable) {
                // ping check is a bonus feature, never fail server creation over it
            }
        }

        // Couldn't get this attempt's IP or start its ping check — if an
        // earlier attempt is already known to be reachable, report that one
        // instead of a bare failure/no-ping message about this broken one.
        if (! $ip && $this->bestServerId !== null) {
            $this->reportBest($bot);

            return;
        }

        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.";

        if ($ip) {
            $message .= "\n🌐 آی‌پی: `{$ip}`";
        }

        $message .= "\n\n{$this->credentials}";

        $keyboard = $serverId
            ? InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$serverId}")
            )
            : null;

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard, parse_mode: 'Markdown');
    }

    /**
     * Used when THIS attempt fails outright (creation failed, or no IP/ping
     * could be obtained) but an earlier attempt already found in the "keep
     * the best of two" retry loop is known-reachable — reports that one
     * instead of just failing, since it's still a perfectly usable server.
     */
    protected function reportBestOrFail(Nutgram $bot, string $failureMessage): void
    {
        if ($this->bestServerId === null) {
            $bot->sendMessage($failureMessage, chat_id: $this->chatId);

            return;
        }

        $this->reportBest($bot);
    }

    protected function reportBest(Nutgram $bot): void
    {
        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.\n".
            "🌐 آی‌پی: `{$this->bestIp}`\n\n".
            "{$this->bestCredentials}\n\n".
            "⚠️ تلاش {$this->attempt} ناموفق بود؛ این نتیجه‌ی یک تلاش قبلی است.";

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$this->bestServerId}")
        );

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard, parse_mode: 'Markdown');
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "⏳ زمان بررسی وضعیت ساخت سرور «{$this->hostname}» به پایان رسید. وضعیت را از داخل پنل سرورها بررسی کنید.",
            chat_id: $this->chatId,
        );
    }
}
