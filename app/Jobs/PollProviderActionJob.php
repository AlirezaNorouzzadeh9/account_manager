<?php

namespace App\Jobs;

use App\Models\Panel;
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
 * DigitalOcean actions (create/resize/rebuild/...) run asynchronously.
 * This job polls GET /v2/actions/{id} until it leaves the "in-progress"
 * state and reports the outcome back to the chat that triggered it.
 */
class PollProviderActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 60;

    public function __construct(
        protected int $panelId,
        protected int|string $actionId,
        protected int $chatId,
        protected string $successMessage,
        protected string $failureMessage,
    ) {
    }

    public function handle(Nutgram $bot): void
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

        $message = $status === 'completed' ? $this->successMessage : $this->failureMessage;
        $keyboard = null;

        if ($status === 'completed' && ($action['resource_type'] ?? null) === 'droplet' && ! empty($action['resource_id'])) {
            $serverId = $action['resource_id'];

            try {
                $server = $client->getServer($serverId);
                $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? null;

                if ($ip) {
                    $message .= "\n🌐 آی‌پی: {$ip}";
                }
            } catch (Throwable) {
                // best-effort only, the "view server" button still lets them check manually
            }

            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🔍 مشاهده سرور', callback_data: "view_server:{$this->panelId}:{$serverId}")
            );
        }

        $bot->sendMessage($message, chat_id: $this->chatId, reply_markup: $keyboard);
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            '⏳ زمان بررسی وضعیت این عملیات به پایان رسید. وضعیت را از داخل پنل سرورها بررسی کنید.',
            chat_id: $this->chatId,
        );
    }
}
