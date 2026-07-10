<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ServerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Waits for the check-host.net Iran ping result. If every Iran node is OK,
 * sends the ONE final "server ready" message (IP + credentials + ping
 * results). If not, and a region/size/image spec is known, this deletes the
 * server and builds another one instead of reporting — repeating up to
 * MAX_ATTEMPTS times — so the admin is only ever notified once, about a
 * server with a clean Iran ping (or, failing that, the last attempt).
 */
class CreateServerFinalReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    protected const MAX_ATTEMPTS = 10;

    public function __construct(
        protected string $requestId,
        protected string $ip,
        protected string $hostname,
        protected string $credentials,
        protected int $chatId,
        protected ?int $panelId = null,
        protected int|string|null $serverId = null,
        protected ?string $region = null,
        protected ?string $size = null,
        protected ?string $image = null,
        protected int $attempt = 1,
    ) {
    }

    public function handle(Nutgram $bot, CheckHostClient $checkHost): void
    {
        $result = $checkHost->getResult($this->requestId);

        if ($result === null) {
            $this->release(5);

            return;
        }

        $clean = $checkHost->allNodesOk($result);

        if ($clean || ! $this->canRetry() || $this->attempt >= self::MAX_ATTEMPTS) {
            $this->send($bot, $checkHost->formatResult($result), $clean);

            return;
        }

        $this->retry($bot, $checkHost->formatResult($result));
    }

    public function failed(?Throwable $exception): void
    {
        $this->send(app(Nutgram::class), 'نتیجه‌ی پینگ آماده نشد.', false);
    }

    protected function canRetry(): bool
    {
        return $this->region !== null && $this->size !== null && $this->image !== null
            && $this->panelId !== null && $this->serverId !== null;
    }

    /**
     * Deletes this attempt and builds another with the same spec, looping
     * the create → ping-check cycle via a fresh CreateServerReadyJob.
     */
    protected function retry(Nutgram $bot, string $pingSection): void
    {
        $panel = Panel::find($this->panelId);

        if (! $panel) {
            $this->send($bot, $pingSection, false);

            return;
        }

        try {
            ProviderManager::forPanel($panel)->deleteServer($this->serverId);
        } catch (ProviderException) {
            // best-effort cleanup of the bad attempt; still worth trying again
        }

        try {
            [$actionId, , $password] = app(ServerProvisioningService::class)->createSilently(
                $panel,
                $this->hostname,
                $this->region,
                $this->size,
                $this->image,
            );
        } catch (ProviderException $e) {
            $bot->sendMessage(
                "❌ تلاش دوباره برای ساخت سرور «{$this->hostname}» با آی‌پی تمیز ناموفق بود:\n{$e->getMessage()}",
                chat_id: $this->chatId,
            );

            return;
        }

        if (! $actionId) {
            $bot->sendMessage("❌ تلاش دوباره برای ساخت سرور «{$this->hostname}» ناموفق بود.", chat_id: $this->chatId);

            return;
        }

        CreateServerReadyJob::dispatch(
            $this->panelId,
            $actionId,
            $this->chatId,
            $this->hostname,
            "👤 کاربر: `root`\n🔑 رمز عبور: `{$password}`",
            $this->region,
            $this->size,
            $this->image,
            $this->attempt + 1,
        );
    }

    protected function send(Nutgram $bot, string $pingSection, bool $clean): void
    {
        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.\n".
            "🌐 آی‌پی: `{$this->ip}`\n\n".
            "{$this->credentials}\n\n".
            "📡 پینگ از ایران:\n{$pingSection}";

        if (! $clean && $this->attempt > 1) {
            $message .= "\n\n⚠️ بعد از {$this->attempt} تلاش، پینگ کاملاً تمیز نشد — همین آخرین سرور نگه داشته شد.";
        }

        $keyboard = null;

        if ($this->panelId && $this->serverId) {
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$this->serverId}"));

            // Only offered when the ping ISN'T already clean — a clean ping
            // means the automatic retry-until-clean loop already did its
            // job, so a manual rebuild button would be redundant.
            if (! $clean) {
                $keyboard->addRow(InlineKeyboardButton::make('🔄 تغییر سرور', callback_data: "recreate_server:{$this->panelId}:{$this->serverId}"));
            }
        }

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard, parse_mode: 'Markdown');
    }
}
