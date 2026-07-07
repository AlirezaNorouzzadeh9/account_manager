<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Deletes the OLD server some time after a successful "🔄 تغییر سرور"
 * replace — dispatched with a delay from ReplaceServerFinishJob once the
 * new server's node/WireGuard setup is confirmed working, giving the admin
 * a window to notice a problem and intervene before the old one is gone.
 */
class DeleteOldServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        protected int $panelId,
        protected string $serverId,
        protected int $chatId,
    ) {
    }

    public function handle(Nutgram $bot): void
    {
        $panel = Panel::find($this->panelId);

        if (! $panel) {
            $bot->sendMessage('این پنل دیگر وجود ندارد؛ سرور قبلی خودکار حذف نشد.', chat_id: $this->chatId);

            return;
        }

        try {
            ProviderManager::forPanel($panel)->deleteServer($this->serverId);
        } catch (ProviderException $e) {
            $bot->sendMessage("❌ حذف خودکار سرور قبلی ناموفق بود:\n{$e->getMessage()}", chat_id: $this->chatId);

            return;
        }

        $bot->sendMessage('🗑 سرور قبلی به‌صورت خودکار حذف شد.', chat_id: $this->chatId);
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            '❌ حذف خودکار سرور قبلی با خطای غیرمنتظره متوقف شد.',
            chat_id: $this->chatId,
        );
    }
}
