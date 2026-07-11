<?php

namespace App\Telegram\Conversations;

use App\Jobs\ReplaceServerPollJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ServerProvisioningService;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\EditsInPlace;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

/**
 * Builds a REPLACEMENT server with the same region/size/image/hostname as an
 * existing one — either tapped manually ("🔄 تغییر سرور" on the server
 * detail screen) or offered automatically when its Iran ping comes back
 * incomplete (a droplet's own IP never changes, so a new IP means a new
 * droplet). Waits for the replacement's ping to be clean (auto retrying up
 * to ReplaceServerPollJob::MAX_ATTEMPTS times), re-applies the old server's
 * PasarGuard node + WireGuard profile onto it, and only THEN — once that's
 * confirmed working — schedules the old server for deletion 5 minutes
 * later (see ReplaceServerFinishJob/DeleteOldServerJob). The old server is
 * never touched before that.
 */
class ReplaceServerConversation extends InlineMenu
{
    use Cancellable;
    use EditsInPlace;

    protected ?int $panelId = null;
    protected ?string $serverId = null;

    public function start(Nutgram $bot, int $panelId, string $serverId): void
    {
        $this->editInPlaceFromCallback($bot);
        $this->panelId = $panelId;
        $this->serverId = $serverId;

        $this->clearButtons();
        $this->menuText(
            "🔄 یک سرور جدید با همان مشخصات (لوکیشن/پلن/سیستم‌عامل) ساخته می‌شود.\n".
            'اگر پینگ آن هم مشکل داشت، خودکار حذف و دوباره ساخته می‌شود (حداکثر '.ReplaceServerPollJob::MAX_ATTEMPTS." بار).\n".
            'به‌محض پینگ سالم، نود + وایرگارد سرور فعلی روی آن پیاده می‌شود؛ اگر موفق بود، سرور فعلی خودکار تا ۵ دقیقه دیگر حذف می‌شود (در همان لحظه می‌توانید بررسی و در صورت نیاز جلوی حذف را بگیرید) — و اگر ناموفق بود، سرور فعلی دست‌نخورده می‌ماند و تایید حذف از شما گرفته می‌شود.'."\n\n".
            'ادامه بدهم؟'
        );
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@cancel'),
            InlineKeyboardButton::make('✅ بله، شروع کن', callback_data: 'yes@confirmReplace'),
        );
        $this->showMenu();
    }

    public function confirmReplace(Nutgram $bot): void
    {
        $panel = Panel::ownedBy($bot->userId())->find($this->panelId);
        $secret = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->first();

        if (! $panel || ! $secret || ! $secret->region || ! $secret->size || ! $secret->image || ! $secret->hostname) {
            $this->closeMenu('❌ اطلاعات کافی برای ساخت سرور جایگزین ذخیره نشده (احتمالاً این سرور قبل از این قابلیت ساخته شده).');
            $this->end();

            return;
        }

        try {
            [$actionId] = app(ServerProvisioningService::class)->createSilently(
                $panel,
                $secret->hostname,
                $secret->region,
                $secret->size,
                $secret->image,
            );
        } catch (ProviderException $e) {
            $this->closeMenu("❌ ساخت سرور جایگزین ناموفق بود:\n{$e->getMessage()}");
            $this->end();

            return;
        }

        if ($actionId) {
            ReplaceServerPollJob::dispatch(
                $this->panelId,
                $this->serverId,
                $actionId,
                $secret->hostname,
                $secret->region,
                $secret->size,
                $secret->image,
                $secret->wireguard_profile_id,
                $bot->chatId(),
                1,
            );
        }

        $this->closeMenu(
            "🚀 ساخت سرور جایگزین شروع شد.\n".
            'به‌محض آماده شدن پینگش چک می‌شود؛ سرور فعلی تا تایید سالم بودن سرور جدید دست‌نخورده می‌ماند.'
        );
        $this->end();
    }
}
