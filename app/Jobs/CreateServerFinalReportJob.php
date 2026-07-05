<?php

namespace App\Jobs;

use App\Services\CheckHost\CheckHostClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Waits for the check-host.net Iran ping result, then sends the ONE final
 * "server ready" message (IP + credentials + ping results) so the user gets
 * a single combined message instead of two separate ones.
 */
class CreateServerFinalReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public function __construct(
        protected string $requestId,
        protected string $ip,
        protected string $hostname,
        protected string $credentials,
        protected int $chatId,
        protected ?int $panelId = null,
        protected int|string|null $serverId = null,
    ) {
    }

    public function handle(Nutgram $bot, CheckHostClient $checkHost): void
    {
        $result = $checkHost->getResult($this->requestId);

        if ($result === null) {
            $this->release(5);

            return;
        }

        $this->send($bot, $checkHost->formatResult($result));
    }

    public function failed(?Throwable $exception): void
    {
        $this->send(app(Nutgram::class), 'نتیجه‌ی پینگ آماده نشد.');
    }

    protected function send(Nutgram $bot, string $pingSection): void
    {
        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.\n".
            "🌐 آی‌پی: {$this->ip}\n\n".
            "{$this->credentials}\n\n".
            "📡 پینگ از ایران:\n{$pingSection}";

        $keyboard = ($this->panelId && $this->serverId)
            ? InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$this->serverId}")
            )
            : null;

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard);
    }
}
