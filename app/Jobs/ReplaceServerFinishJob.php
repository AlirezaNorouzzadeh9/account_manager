<?php

namespace App\Jobs;

use App\Models\ServerSecret;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * The replacement droplet's ping came back clean — re-applies the PasarGuard
 * node + all saved WireGuard locations onto it, then asks the user to
 * confirm deleting the old one. The old server is left completely alone
 * until that explicit confirmation (see the "delete_old_server" route).
 */
class ReplaceServerFinishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        protected int $oldPanelId,
        protected string $oldServerId,
        protected string $newServerId,
        protected string $newIp,
        protected int $chatId,
    ) {
    }

    public function handle(Nutgram $bot, PasarguardNodeInstaller $installer): void
    {
        $newSecret = ServerSecret::where('panel_id', $this->oldPanelId)
            ->where('provider_server_id', $this->newServerId)
            ->first();

        if (! $newSecret) {
            $bot->sendMessage('❌ رمز روت سرور جایگزین پیدا نشد؛ تنظیمات پیاده نشد.', chat_id: $this->chatId);

            return;
        }

        try {
            $result = $installer->install($this->newIp, 'root', $newSecret->root_password);
            $statusMessage = ($result['success'] ? '✅ ' : '⚠️ ').$result['message'];
        } catch (Throwable $e) {
            $statusMessage = "⚠️ سرور جایگزین ساخته شد ولی نصب نود ناموفق بود:\n{$e->getMessage()}\n".
                'می‌توانید بعداً دستی از «اطلاعات سرور» نود کنید.';
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                '🔍 مشاهده سرور جدید',
                callback_data: "view_server:{$this->oldPanelId}:{$this->newServerId}"
            ))
            ->addRow(InlineKeyboardButton::make(
                '🗑 بله، سرور قبلی حذف شود',
                callback_data: "delete_old_server:{$this->oldPanelId}:{$this->oldServerId}"
            ));

        $bot->sendMessage(
            "{$statusMessage}\n\n".
            "🌐 آی‌پی جدید: {$this->newIp}\n\n".
            'سرور قبلی هنوز حذف نشده. اگر همه چیز روی سرور جدید مرتب است، برای حذف سرور قبلی تایید کنید:',
            chat_id: $this->chatId,
            reply_markup: $keyboard,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "❌ پیاده‌سازی تنظیمات روی سرور جایگزین (IP: {$this->newIp}) با خطا متوقف شد. سرور قبلی دست‌نخورده باقی ماند.",
            chat_id: $this->chatId,
        );
    }
}
