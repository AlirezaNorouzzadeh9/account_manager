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
use Throwable;

/**
 * Decides what to do with a replacement droplet's Iran ping result: clean =>
 * hand off to ReplaceServerFinishJob to copy over the old server's node/
 * WireGuard setup. Not clean => compare this attempt against the best one
 * seen so far, keep only the winner (deleting the loser), and build ANOTHER
 * fresh candidate to test next — repeating up to
 * ReplaceServerPollJob::MAX_ATTEMPTS times. At most two servers ever exist at
 * once this way (the reigning best + the new candidate under test); the OLD
 * server being replaced is never touched here regardless of outcome — once
 * attempts run out, the best candidate found (even if not perfectly clean)
 * is what gets handed to ReplaceServerFinishJob.
 */
class ReplaceServerPingCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public function __construct(
        protected string $requestId,
        protected int $oldPanelId,
        protected string $oldServerId,
        protected string $newServerId,
        protected string $newIp,
        protected string $hostname,
        protected string $region,
        protected string $size,
        protected string $image,
        protected ?int $wireguardProfileId,
        protected int $chatId,
        protected int $attempt,
        protected int|string|null $bestServerId = null,
        protected ?string $bestIp = null,
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

        $okCount = $checkHost->okNodeCount($result);

        if ($checkHost->allNodesOk($result)) {
            $this->deleteBestIfExists();
            $this->finish($this->newServerId, $this->newIp);

            return;
        }

        if ($this->attempt >= ReplaceServerPollJob::MAX_ATTEMPTS) {
            $this->finishWithBestOf($bot, $okCount);

            return;
        }

        $this->retry($bot, $okCount);
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
        if ($this->hasBest()) {
            $this->deleteServerSilently($this->bestServerId);
        }
    }

    protected function deleteServerSilently(int|string $serverId): void
    {
        $panel = Panel::find($this->oldPanelId);

        if (! $panel) {
            return;
        }

        try {
            ProviderManager::forPanel($panel)->deleteServer($serverId);
        } catch (ProviderException) {
            // best-effort cleanup of a discarded attempt
        }
    }

    /** Hands the winning candidate off to install node/WireGuard + schedule the old server's deletion. */
    protected function finish(int|string $winnerServerId, string $winnerIp): void
    {
        ReplaceServerFinishJob::dispatch(
            $this->oldPanelId,
            $this->oldServerId,
            $winnerServerId,
            $winnerIp,
            $this->wireguardProfileId,
            $this->chatId,
        );
    }

    /** Attempts exhausted without a clean ping — keep whichever candidate scored higher. */
    protected function finishWithBestOf(Nutgram $bot, int $currentOkCount): void
    {
        if ($this->hasBest() && $this->bestOkCount >= $currentOkCount) {
            $this->deleteServerSilently($this->newServerId);
            $bot->sendMessage(
                "⚠️ پینگ سرور جایگزین بعد از {$this->attempt} تلاش کاملاً تمیز نشد — بهترین سرور پیدا‌شده (با بیشترین پینگ موفق) انتخاب شد.",
                chat_id: $this->chatId,
            );
            $this->finish($this->bestServerId, $this->bestIp);

            return;
        }

        $this->deleteBestIfExists();
        $bot->sendMessage(
            "⚠️ پینگ سرور جایگزین بعد از {$this->attempt} تلاش کاملاً تمیز نشد — بهترین سرور پیدا‌شده (با بیشترین پینگ موفق) انتخاب شد.",
            chat_id: $this->chatId,
        );
        $this->finish($this->newServerId, $this->newIp);
    }

    /** Ping wasn't clean and attempts remain: keep the winner, build a FRESH candidate to test next round. */
    protected function retry(Nutgram $bot, int $currentOkCount): void
    {
        if ($this->currentBeatsBest($currentOkCount)) {
            $this->deleteBestIfExists();
            [$newBestServerId, $newBestIp, $newBestOkCount] = [$this->newServerId, $this->newIp, $currentOkCount];
        } else {
            $this->deleteServerSilently($this->newServerId);
            [$newBestServerId, $newBestIp, $newBestOkCount] = [$this->bestServerId, $this->bestIp, $this->bestOkCount];
        }

        $panel = Panel::find($this->oldPanelId);

        if (! $panel) {
            $bot->sendMessage(
                "❌ پنل مربوطه دیگر پیدا نشد. سرور جایگزین باقی‌مانده (IP: {$newBestIp}) را دستی بررسی کنید.",
                chat_id: $this->chatId,
            );

            return;
        }

        try {
            [$actionId] = app(ServerProvisioningService::class)->createSilently(
                $panel,
                $this->hostname,
                $this->region,
                $this->size,
                $this->image,
            );
        } catch (ProviderException $e) {
            $bot->sendMessage(
                "❌ تلاش دوباره برای ساخت سرور جایگزین ناموفق بود:\n{$e->getMessage()}\nسرور قبلی دست‌نخورده باقی ماند.",
                chat_id: $this->chatId,
            );

            return;
        }

        ReplaceServerPollJob::dispatch(
            $this->oldPanelId,
            $this->oldServerId,
            $actionId,
            $this->hostname,
            $this->region,
            $this->size,
            $this->image,
            $this->wireguardProfileId,
            $this->chatId,
            $this->attempt + 1,
            $newBestServerId,
            $newBestIp,
            $newBestOkCount,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            '⏳ نتیجه‌ی پینگ سرور جایگزین آماده نشد. سرور قبلی دست‌نخورده باقی ماند.',
            chat_id: $this->chatId,
        );
    }
}
