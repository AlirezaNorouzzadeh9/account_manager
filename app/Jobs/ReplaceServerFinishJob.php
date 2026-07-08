<?php

namespace App\Jobs;

use App\Models\ServerSecret;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use App\Services\Pasarguard\PasarguardNodeReconnector;
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
 * node + the old server's WireGuard profile onto it. If that succeeds, the
 * old server is automatically deleted 5 minutes later (DeleteOldServerJob),
 * giving the admin a short window to notice a problem first. If it
 * doesn't succeed, nothing is deleted automatically — a manual confirm
 * button is shown instead (see the "delete_old_server" route).
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
        protected ?int $wireguardProfileId,
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

        // Keep the same WireGuard profile as the old server going forward
        // (e.g. for a later manual "🔄 بروزرسانی وایرگاردها").
        $newSecret->update(['wireguard_profile_id' => $this->wireguardProfileId]);
        $profile = $newSecret->wireguardProfile;

        $domain = null;
        $dnsWarning = null;
        $succeeded = false;

        try {
            $result = $installer->install($this->newIp, 'root', $newSecret->root_password, $profile?->private_key, $profile?->name);
            $statusMessage = ($result['success'] ? '✅ ' : '⚠️ ').$result['message'];
            $domain = $result['domain'] ?? null;
            $dnsWarning = $result['dns_warning'] ?? null;
            $succeeded = $result['success'];
        } catch (Throwable $e) {
            $statusMessage = "⚠️ سرور جایگزین ساخته شد ولی نصب نود ناموفق بود:\n{$e->getMessage()}\n".
                'می‌توانید بعداً دستی از «اطلاعات سرور» نود کنید.';
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '🔍 مشاهده سرور جدید',
            callback_data: "view_server:{$this->oldPanelId}:{$this->newServerId}"
        ));

        if ($succeeded) {
            // Give the admin a short window to notice a problem before the
            // old server is gone for good, rather than deleting instantly.
            DeleteOldServerJob::dispatch($this->oldPanelId, $this->oldServerId, $this->chatId)
                ->delay(now()->addMinutes(5));
            $oldServerLine = '⏳ سرور قبلی تا ۵ دقیقه‌ی دیگر به‌صورت خودکار حذف می‌شود. اگر مشکلی می‌بینید همین حالا از «سرورهای من» بررسی کنید.';
        } else {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🗑 بله، سرور قبلی حذف شود',
                callback_data: "delete_old_server:{$this->oldPanelId}:{$this->oldServerId}"
            ));
            $oldServerLine = 'سرور قبلی هنوز حذف نشده. اگر همه چیز روی سرور جدید مرتب است، برای حذف سرور قبلی تایید کنید:';
        }

        $domainLine = $domain
            ? "🪪 آدرس نود (برای پنل PasarGuard): `{$domain}`\n".
                app(PasarguardNodeReconnector::class)->reminder($domain, $profile)."\n\n"
            : '';
        $dnsWarningLine = $dnsWarning ? "⚠️ {$dnsWarning}\n\n" : '';

        $bot->sendMessage(
            "{$statusMessage}\n\n".
            "🌐 آی‌پی جدید: `{$this->newIp}`\n\n".
            $domainLine.
            $dnsWarningLine.
            $oldServerLine,
            chat_id: $this->chatId,
            reply_markup: $keyboard,
            parse_mode: 'Markdown',
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
