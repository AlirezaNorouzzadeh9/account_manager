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
 * WireGuard setup; not clean => delete this attempt and try again (up to
 * ReplaceServerPollJob::MAX_ATTEMPTS total) before giving up. The old server
 * is never touched here either way.
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
    ) {
    }

    public function handle(Nutgram $bot, CheckHostClient $checkHost): void
    {
        $result = $checkHost->getResult($this->requestId);

        if ($result === null) {
            $this->release(5);

            return;
        }

        if ($checkHost->allNodesOk($result)) {
            ReplaceServerFinishJob::dispatch(
                $this->oldPanelId,
                $this->oldServerId,
                $this->newServerId,
                $this->newIp,
                $this->wireguardProfileId,
                $this->chatId,
            );

            return;
        }

        if ($this->attempt >= ReplaceServerPollJob::MAX_ATTEMPTS) {
            $bot->sendMessage(
                "❌ پینگ سرور جایگزین بعد از {$this->attempt} تلاش هم مشکل داشت:\n".
                $checkHost->formatResult($result)."\n\n".
                "آخرین سرور ساخته‌شده (IP: {$this->newIp}) نگه داشته شد، خودتان بررسی/حذفش کنید. سرور قبلی هم دست‌نخورده باقی ماند.",
                chat_id: $this->chatId,
            );

            return;
        }

        $panel = Panel::find($this->oldPanelId);

        if (! $panel) {
            $bot->sendMessage(
                "❌ پنل مربوطه دیگر پیدا نشد. سرور جایگزین ناموفق (IP: {$this->newIp}) را دستی حذف کنید.",
                chat_id: $this->chatId,
            );

            return;
        }

        try {
            ProviderManager::forPanel($panel)->deleteServer($this->newServerId);
        } catch (ProviderException) {
            // best-effort cleanup of the bad attempt; still worth trying again
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
