<?php

namespace App\Jobs;

use App\Services\CheckHost\CheckHostClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * On-demand Iran ping check for an existing server, triggered manually via
 * "📡 پینگ از ایران" on the server detail screen — unlike CheckServerPingJob
 * (the silent, problem-only periodic monitor), this always reports the
 * result since the admin explicitly asked for it.
 */
class ServerPingCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public function __construct(
        protected string $requestId,
        protected string $ip,
        protected string $hostname,
        protected int $chatId,
    ) {
    }

    public function handle(Nutgram $bot, CheckHostClient $checkHost): void
    {
        $result = $checkHost->getResult($this->requestId);

        if ($result === null) {
            $this->release(5);

            return;
        }

        $status = $checkHost->allNodesOk($result) ? '✅' : '⚠️';

        $bot->sendMessage(
            "{$status} پینگ سرور «{$this->hostname}» ({$this->ip}) از ایران:\n".
            $checkHost->formatResult($result),
            chat_id: $this->chatId,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "⏳ نتیجه‌ی پینگ سرور «{$this->hostname}» آماده نشد.",
            chat_id: $this->chatId,
        );
    }
}
