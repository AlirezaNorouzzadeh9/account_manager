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
use Throwable;

/**
 * Part of the "replace server" flow (see ReplaceServerConversation): waits
 * for a replacement droplet to finish provisioning, then kicks off its Iran
 * ping check. Whether to keep it, retry, or give up is decided by
 * ReplaceServerPingCheckJob once that ping result is in.
 */
class ReplaceServerPollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 60;

    /** Total create attempts (including the first) before giving up on a clean ping. */
    public const MAX_ATTEMPTS = 10;

    public function __construct(
        protected int $oldPanelId,
        protected string $oldServerId,
        protected int|string $newServerActionId,
        protected string $hostname,
        protected string $region,
        protected string $size,
        protected string $image,
        protected ?int $wireguardProfileId,
        protected int $chatId,
        protected int $attempt,
        // The best candidate found in an earlier round, if any (see
        // ReplaceServerPingCheckJob's "keep the best of two" retry logic) —
        // the old server being replaced is separate and never part of this.
        protected int|string|null $bestServerId = null,
        protected ?string $bestIp = null,
        protected ?int $bestOkCount = null,
    ) {
    }

    public function handle(Nutgram $bot, CheckHostClient $checkHost): void
    {
        $panel = Panel::find($this->oldPanelId);

        if (! $panel) {
            return;
        }

        $client = ProviderManager::forPanel($panel);
        $action = $client->getAction($this->newServerActionId);
        $status = $action['status'] ?? 'in-progress';

        if ($status === 'in-progress') {
            $this->release(10);

            return;
        }

        if ($status !== 'completed') {
            $bot->sendMessage(
                "❌ ساخت سرور جایگزین (تلاش {$this->attempt}) ناموفق بود. سرور قبلی دست‌نخورده باقی ماند.",
                chat_id: $this->chatId,
            );

            return;
        }

        $newServerId = $action['resource_id'] ?? null;

        if (! $newServerId) {
            $bot->sendMessage('❌ شناسه‌ی سرور جایگزین در دسترس نبود. سرور قبلی دست‌نخورده باقی ماند.', chat_id: $this->chatId);

            return;
        }

        $ip = null;

        try {
            $server = $client->getServer($newServerId);
            $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null;
        } catch (Throwable) {
        }

        if (! $ip) {
            $this->release(10);

            return;
        }

        try {
            $requestId = $checkHost->requestPing($ip);
        } catch (Throwable) {
            $bot->sendMessage(
                "⚠️ سرور جایگزین ساخته شد (IP: {$ip}) ولی درخواست پینگ ناموفق بود. دستی بررسی کنید. سرور قبلی دست‌نخورده باقی ماند.",
                chat_id: $this->chatId,
            );

            return;
        }

        ReplaceServerPingCheckJob::dispatch(
            $requestId,
            $this->oldPanelId,
            $this->oldServerId,
            (string) $newServerId,
            $ip,
            $this->hostname,
            $this->region,
            $this->size,
            $this->image,
            $this->wireguardProfileId,
            $this->chatId,
            $this->attempt,
            $this->bestServerId,
            $this->bestIp,
            $this->bestOkCount,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "⏳ زمان بررسی ساخت سرور جایگزین (تلاش {$this->attempt}) به پایان رسید. سرور قبلی دست‌نخورده باقی ماند.",
            chat_id: $this->chatId,
        );
    }
}
