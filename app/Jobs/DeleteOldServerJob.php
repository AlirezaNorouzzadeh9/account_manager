<?php

namespace App\Jobs;

use App\Models\Panel;
use App\Services\Pasarguard\PasarguardPanelClient;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Deletes the OLD server (and, if a replacement panel node was registered
 * for it, the OLD PasarGuard node too) some time after a successful
 * "🔄 تغییر سرور" replace — dispatched with a delay from
 * ReplaceServerFinishJob once the new server's node/WireGuard setup is
 * confirmed working, giving the admin a window to notice a problem and
 * intervene before the old ones are gone.
 */
class DeleteOldServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        protected int $panelId,
        protected string $serverId,
        protected int $chatId,
        protected ?int $oldNodeId = null,
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

        $message = '🗑 سرور قبلی به‌صورت خودکار حذف شد.';

        if ($this->oldNodeId) {
            $message .= "\n".$this->deleteOldNode($this->oldNodeId);
        }

        $bot->sendMessage($message, chat_id: $this->chatId);
    }

    protected function deleteOldNode(int $nodeId): string
    {
        if (! filled(config('pasarguard.panel.url')) || ! filled(config('pasarguard.panel.username')) || ! filled(config('pasarguard.panel.password'))) {
            return "⚠️ اطلاعات پنل PasarGuard تنظیم نشده؛ نود قبلی (id={$nodeId}) را دستی از پنل حذف کنید.";
        }

        try {
            (new PasarguardPanelClient(
                config('pasarguard.panel.url'),
                config('pasarguard.panel.username'),
                config('pasarguard.panel.password'),
            ))->deleteNode($nodeId);

            return "🗑 نود قبلی (id={$nodeId}) هم از پنل PasarGuard حذف شد.";
        } catch (Throwable $e) {
            return "⚠️ حذف نود قبلی (id={$nodeId}) از پنل ناموفق بود ({$e->getMessage()})؛ دستی حذفش کنید.";
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            '❌ حذف خودکار سرور قبلی با خطای غیرمنتظره متوقف شد.',
            chat_id: $this->chatId,
        );
    }
}
