<?php

namespace App\Telegram\Conversations;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\ServerProvisioningService;
use App\Telegram\Support\Cancellable;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Deletes a server and creates a fresh one with the same region/size/image/
 * hostname — offered when the Iran ping check comes back incomplete, since a
 * new droplet gets a new IP (a droplet's own IP can never be changed).
 */
class RecreateServerConversation extends InlineMenu
{
    use Cancellable;

    protected ?int $panelId = null;
    protected ?string $serverId = null;

    public function start(Nutgram $bot, int $panelId, string $serverId): void
    {
        $this->panelId = $panelId;
        $this->serverId = $serverId;

        $this->clearButtons();
        $this->menuText(
            "⚠️ این کار سرور فعلی را کامل حذف می‌کند و با همان مشخصات (لوکیشن/پلن/سیستم‌عامل) یک سرور تازه با آی‌پی جدید می‌سازد.\n".
            'مطمئن هستید؟'
        );
        $this->addButtonRow(InlineKeyboardButton::make('✅ بله، پاک کن و دوباره بساز', callback_data: 'yes@confirmRecreate'));
        $this->addButtonRow(InlineKeyboardButton::make('❌ نه', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function confirmRecreate(Nutgram $bot): void
    {
        $panel = Panel::find($this->panelId);
        $secret = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->first();

        if (! $panel || ! $secret || ! $secret->region || ! $secret->size || ! $secret->image || ! $secret->hostname) {
            $this->closeMenu('❌ اطلاعات کافی برای ساخت دوباره ذخیره نشده (احتمالاً این سرور قبل از این قابلیت ساخته شده).');
            $this->end();

            return;
        }

        try {
            ProviderManager::forPanel($panel)->deleteServer($this->serverId);
        } catch (ProviderException $e) {
            $this->closeMenu("❌ حذف سرور قبلی ناموفق بود:\n{$e->getMessage()}");
            $this->end();

            return;
        }

        try {
            app(ServerProvisioningService::class)->create(
                $panel,
                $secret->hostname,
                $secret->region,
                $secret->size,
                $secret->image,
                $bot->chatId(),
            );
        } catch (ProviderException $e) {
            $this->closeMenu("🗑 سرور قبلی حذف شد ولی ساخت سرور جدید ناموفق بود:\n{$e->getMessage()}");
            $this->end();

            return;
        }

        $this->closeMenu(
            "🗑 سرور قبلی حذف شد.\n".
            '🚀 درخواست ساخت سرور جدید با همان مشخصات ثبت شد؛ آی‌پی و نتیجه‌ی پینگ به‌محض آماده شدن ارسال می‌شود.'
        );
        $this->end();
    }
}
