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
 * reports that server and deletes whatever earlier attempt was being kept as
 * the "best so far" (if any). If not, and a region/size/image spec is known,
 * this compares the current attempt against the best one seen so far, keeps
 * only the winner (deleting the loser), and builds ANOTHER fresh candidate to
 * test next — repeating up to MAX_ATTEMPTS times. At most two servers ever
 * exist at once this way: the reigning "best so far" and the one new
 * candidate currently being judged against it. Once attempts run out, the
 * best one found (even if not perfectly clean) is what gets reported.
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
        // The best candidate found in an earlier round, if any (see class docblock).
        protected int|string|null $bestServerId = null,
        protected ?string $bestIp = null,
        protected ?string $bestCredentials = null,
        protected ?int $bestOkCount = null,
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
        $okCount = $checkHost->okNodeCount($result);

        if ($clean) {
            $this->deleteBestIfExists();
            $this->send($bot, $checkHost->formatResult($result), true, $this->serverId, $this->ip, $this->credentials);

            return;
        }

        if (! $this->canRetry() || $this->attempt >= self::MAX_ATTEMPTS) {
            $this->finishWithBestOf($bot, $checkHost->formatResult($result), $okCount);

            return;
        }

        $this->retry($bot, $checkHost->formatResult($result), $okCount);
    }

    public function failed(?Throwable $exception): void
    {
        $this->finishWithBestOf(app(Nutgram::class), 'نتیجه‌ی پینگ آماده نشد.', 0);
    }

    protected function canRetry(): bool
    {
        return $this->region !== null && $this->size !== null && $this->image !== null
            && $this->panelId !== null && $this->serverId !== null;
    }

    protected function hasBest(): bool
    {
        return $this->bestServerId !== null;
    }

    protected function currentBeatsBest(int $currentOkCount): bool
    {
        return ! $this->hasBest() || $currentOkCount > $this->bestOkCount;
    }

    protected function deleteBestIfExists(): void
    {
        if (! $this->hasBest()) {
            return;
        }

        $this->deleteServerSilently($this->bestServerId);
    }

    protected function deleteServerSilently(int|string $serverId): void
    {
        $panel = Panel::find($this->panelId);

        if (! $panel) {
            return;
        }

        try {
            ProviderManager::forPanel($panel)->deleteServer($serverId);
        } catch (ProviderException) {
            // best-effort cleanup of a discarded attempt
        }
    }

    /**
     * Attempts exhausted (or retry became impossible) without a clean ping —
     * keeps whichever of [current attempt, best-so-far] scored higher and
     * reports it as the final result.
     */
    protected function finishWithBestOf(Nutgram $bot, string $pingSection, int $currentOkCount): void
    {
        if ($this->hasBest() && $this->bestOkCount >= $currentOkCount) {
            $this->deleteServerSilently($this->serverId);
            $this->send($bot, $pingSection, false, $this->bestServerId, $this->bestIp, $this->bestCredentials);

            return;
        }

        $this->deleteBestIfExists();
        $this->send($bot, $pingSection, false, $this->serverId, $this->ip, $this->credentials);
    }

    /**
     * Ping wasn't clean and attempts remain: keep whichever of [current
     * attempt, best-so-far] scored higher (deleting the loser), then build a
     * FRESH candidate to test next round.
     */
    protected function retry(Nutgram $bot, string $pingSection, int $currentOkCount): void
    {
        if ($this->currentBeatsBest($currentOkCount)) {
            $this->deleteBestIfExists();
            [$newBestServerId, $newBestIp, $newBestCredentials, $newBestOkCount] =
                [$this->serverId, $this->ip, $this->credentials, $currentOkCount];
        } else {
            $this->deleteServerSilently($this->serverId);
            [$newBestServerId, $newBestIp, $newBestCredentials, $newBestOkCount] =
                [$this->bestServerId, $this->bestIp, $this->bestCredentials, $this->bestOkCount];
        }

        $panel = Panel::find($this->panelId);

        if (! $panel) {
            $this->send($bot, $pingSection, false, $newBestServerId, $newBestIp, $newBestCredentials);

            return;
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
                "⚠️ تلاش دوباره برای ساخت سرور «{$this->hostname}» با آی‌پی تمیز ناموفق بود:\n{$e->getMessage()}\n".
                'بهترین سرور پیدا شده تا الان نگه داشته شد.',
                chat_id: $this->chatId,
            );
            $this->send($bot, $pingSection, false, $newBestServerId, $newBestIp, $newBestCredentials);

            return;
        }

        if (! $actionId) {
            $this->send($bot, $pingSection, false, $newBestServerId, $newBestIp, $newBestCredentials);

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
            $newBestServerId,
            $newBestIp,
            $newBestCredentials,
            $newBestOkCount,
        );
    }

    protected function send(
        Nutgram $bot,
        string $pingSection,
        bool $clean,
        int|string|null $finalServerId,
        ?string $finalIp,
        ?string $finalCredentials,
    ): void {
        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.\n".
            "🌐 آی‌پی: `{$finalIp}`\n\n".
            "{$finalCredentials}\n\n".
            "📡 پینگ از ایران:\n{$pingSection}";

        if (! $clean && $this->attempt > 1) {
            $message .= "\n\n⚠️ بعد از {$this->attempt} تلاش، پینگ کاملاً تمیز نشد — بهترین سرور پیدا‌شده (با بیشترین پینگ موفق) نگه داشته شد.";
        }

        $keyboard = null;

        if ($this->panelId && $finalServerId) {
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$finalServerId}"));

            // Only offered when the ping ISN'T already clean — a clean ping
            // means the automatic retry-until-clean loop already did its
            // job, so a manual rebuild button would be redundant.
            if (! $clean) {
                $keyboard->addRow(InlineKeyboardButton::make('🔄 تغییر سرور', callback_data: "recreate_server:{$this->panelId}:{$finalServerId}"));
            }
        }

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard, parse_mode: 'Markdown');
    }
}
