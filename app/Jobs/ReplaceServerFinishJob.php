<?php

namespace App\Jobs;

use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use App\Services\Pasarguard\PasarguardPanelClient;
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
 * node (its own per-IP cert, not a shared domain) + the old server's
 * WireGuard profile onto it. If that succeeds, a brand-new node is
 * registered in the PasarGuard panel for the new IP (WireguardProfile::core_id
 * is updated to point at it) and, 5 minutes later, both the old server AND
 * its old panel node are deleted (DeleteOldServerJob) — giving the admin a
 * short window to notice a problem first. If the install itself doesn't
 * succeed, nothing is deleted automatically — a manual confirm button is
 * shown instead (see the "delete_old_server" route).
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

        $succeeded = false;
        $cert = null;

        try {
            // No profile name/domain passed: every node this flow creates
            // gets its own per-IP cert and its own fresh panel registration
            // (see registerNewNode()) instead of sharing a stable domain.
            $result = $installer->install($this->newIp, 'root', $newSecret->root_password, $profile?->private_key);
            $statusMessage = ($result['success'] ? '✅ ' : '⚠️ ').$result['message'];
            $succeeded = $result['success'];
            $cert = $result['cert'] ?? null;
        } catch (Throwable $e) {
            $statusMessage = "⚠️ سرور جایگزین ساخته شد ولی نصب نود ناموفق بود:\n{$e->getMessage()}\n".
                'می‌توانید بعداً دستی از «اطلاعات سرور» نود کنید.';
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '🔍 مشاهده سرور جدید',
            callback_data: "view_server:{$this->oldPanelId}:{$this->newServerId}"
        ));

        $nodeLine = '';
        $oldNodeId = null;

        if ($succeeded && $profile && $cert) {
            ['line' => $nodeLine, 'oldNodeId' => $oldNodeId] = $this->registerNewNode($profile, $this->newIp, $cert);
        }

        if ($succeeded) {
            // Give the admin a short window to notice a problem before the
            // old server (and its old panel node, if one was replaced) is
            // gone for good, rather than deleting instantly.
            DeleteOldServerJob::dispatch($this->oldPanelId, $this->oldServerId, $this->chatId, $oldNodeId)
                ->delay(now()->addMinutes(5));
            $oldServerLine = $oldNodeId
                ? '⏳ سرور و نود قبلی تا ۵ دقیقه‌ی دیگر به‌صورت خودکار حذف می‌شوند. اگر مشکلی می‌بینید همین حالا از «سرورهای من» بررسی کنید.'
                : '⏳ سرور قبلی تا ۵ دقیقه‌ی دیگر به‌صورت خودکار حذف می‌شود. اگر مشکلی می‌بینید همین حالا از «سرورهای من» بررسی کنید.';
        } else {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🗑 بله، سرور قبلی حذف شود',
                callback_data: "delete_old_server:{$this->oldPanelId}:{$this->oldServerId}"
            ));
            $oldServerLine = 'سرور قبلی هنوز حذف نشده. اگر همه چیز روی سرور جدید مرتب است، برای حذف سرور قبلی تایید کنید:';
        }

        $bot->sendMessage(
            "{$statusMessage}\n\n".
            "🌐 آی‌پی جدید: `{$this->newIp}`\n\n".
            $nodeLine.
            $oldServerLine,
            chat_id: $this->chatId,
            reply_markup: $keyboard,
            parse_mode: 'Markdown',
        );
    }

    /**
     * Registers a brand-new PasarGuard panel node for the replacement
     * server's own IP (inheriting the OLD node's core_config_id so Xray
     * configs don't change), and points the profile at it. Falls back to
     * asking the admin to register manually when there's no prior node to
     * inherit from, or the panel isn't configured, or the call fails.
     *
     * @return array{line: string, oldNodeId: ?int}
     */
    protected function registerNewNode(WireguardProfile $profile, string $ip, string $cert): array
    {
        if (! $profile->core_id) {
            return ['line' => '', 'oldNodeId' => null];
        }

        if (! filled(config('pasarguard.panel.url')) || ! filled(config('pasarguard.panel.username')) || ! filled(config('pasarguard.panel.password'))) {
            return [
                'line' => "⚠️ اطلاعات پنل PasarGuard تنظیم نشده؛ نود جدید را دستی با IP {$ip} در پنل ثبت کنید.\n\n",
                'oldNodeId' => null,
            ];
        }

        $oldNodeId = $profile->core_id;
        $client = new PasarguardPanelClient(
            config('pasarguard.panel.url'),
            config('pasarguard.panel.username'),
            config('pasarguard.panel.password'),
        );

        try {
            $oldNode = $client->getNode($oldNodeId);
            $coreConfigId = (int) ($oldNode['core_config_id'] ?? 1);

            $newNodeId = $client->createNode([
                'name' => "{$profile->name} ({$ip})",
                'address' => $ip,
                'port' => PasarguardNodeInstaller::NODE_PORT,
                'api_port' => PasarguardNodeInstaller::NODE_API_PORT,
                'connection_type' => 'grpc',
                'server_ca' => $cert,
                'keep_alive' => 60,
                'core_config_id' => $coreConfigId,
                'api_key' => config('pasarguard.api_key'),
            ]);

            $profile->update(['core_id' => $newNodeId]);

            return [
                'line' => "✅ نود جدید (id={$newNodeId}) در پنل PasarGuard با IP {$ip} ساخته و ثبت شد.\n\n",
                'oldNodeId' => $oldNodeId,
            ];
        } catch (Throwable $e) {
            return [
                'line' => "⚠️ ساخت خودکار نود جدید در پنل ناموفق بود ({$e->getMessage()})؛ دستی با IP {$ip} در پنل ثبت کنید.\n\n",
                'oldNodeId' => null,
            ];
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(Nutgram::class)->sendMessage(
            "❌ پیاده‌سازی تنظیمات روی سرور جایگزین (IP: {$this->newIp}) با خطا متوقف شد. سرور قبلی دست‌نخورده باقی ماند.",
            chat_id: $this->chatId,
        );
    }
}
