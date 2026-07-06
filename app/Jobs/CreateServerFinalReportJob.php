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

        $this->send($bot, $checkHost->formatResult($result), $checkHost->allNodesOk($result));
    }

    public function failed(?Throwable $exception): void
    {
        // Never got a usable result at all — treat that the same as "not all nodes ok".
        $this->send(app(Nutgram::class), 'نتیجه‌ی پینگ آماده نشد.', false);
    }

    protected function send(Nutgram $bot, string $pingSection, bool $pingWasComplete): void
    {
        $message = "✅ سرور «{$this->hostname}» با موفقیت ساخته شد.\n".
            "🌐 آی‌پی: {$this->ip}\n\n".
            "{$this->credentials}\n\n".
            "📡 پینگ از ایران:\n{$pingSection}";

        $keyboard = null;

        if ($this->panelId && $this->serverId) {
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$this->serverId}")
            );

            if (! $pingWasComplete) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    '🔄 تغییر سرور',
                    callback_data: "replace_server:{$this->panelId}:{$this->serverId}"
                ));
            }
        }

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard);
    }
}
