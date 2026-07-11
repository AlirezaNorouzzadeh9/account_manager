<?php

namespace App\Telegram\Conversations;

use App\Jobs\InstallPasarguardNodeJob;
use App\Jobs\PollProviderActionJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use App\Services\Providers\ProviderClient;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\EditsInPlace;
use App\Telegram\Support\FiltersUbuntuImages;
use App\Telegram\Support\FormatsRtlText;
use App\Telegram\Support\FormatsServerSize;
use App\Telegram\Support\GridButtons;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class ServerListMenu extends InlineMenu
{
    use Cancellable;
    use EditsInPlace;
    use FiltersUbuntuImages;
    use FormatsRtlText;
    use FormatsServerSize;
    use GridButtons;

    protected ?int $panelId = null;
    protected int $page = 1;
    protected int|string|null $serverId = null;
    protected ?string $pendingImage = null;

    /** The Telegram user this conversation instance belongs to — set once in start(), used by panel() as the ownership check for every screen/action below it. */
    protected ?int $ownerId = null;

    /** 'none' or a numeric WireguardProfile id, chosen right before install/update-wireguards. */
    protected ?string $pendingProfile = null;

    protected function panel(): Panel
    {
        return Panel::ownedBy($this->ownerId)->findOrFail($this->panelId);
    }

    protected function client(): ProviderClient
    {
        return ProviderManager::forPanel($this->panel());
    }

    /**
     * $jumpPanelId/$jumpServerId let a "🔍 مشاهده سرور" button (sent from
     * PollProviderActionJob) open this conversation straight on a specific
     * server's detail screen instead of the panel picker.
     */
    public function start(Nutgram $bot, ?int $jumpPanelId = null, ?string $jumpServerId = null): void
    {
        $this->ownerId = $bot->userId();

        if ($jumpPanelId !== null && $jumpServerId !== null) {
            $this->panelId = $jumpPanelId;
            $this->serverId = $jumpServerId;
            $this->renderServerDetail($bot);
            return;
        }

        $this->editInPlaceFromCallback($bot);

        $panels = Panel::query()->ownedBy($this->ownerId)->active()->get();

        $this->clearButtons();

        if ($panels->isEmpty()) {
            $this->menuText("هیچ پنل فعالی وجود ندارد.\nابتدا از منوی «پنل‌های من» یک پنل اضافه کنید.");
            $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@cancel'));
            $this->showMenu();
            return;
        }

        $this->menuText('سرورهای کدام پنل را می‌خواهید ببینید؟');

        $this->addButtonGrid($panels->map(fn (Panel $panel) => InlineKeyboardButton::make(
            "🖥 {$panel->name} ({$panel->provider->label()})",
            callback_data: "{$panel->id}@choosePanel"
        ))->all());

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function choosePanel(Nutgram $bot, string $data): void
    {
        $this->panelId = (int) $data;
        $this->page = 1;
        $this->renderList($bot);
    }

    public function nextPage(Nutgram $bot): void
    {
        $this->page++;
        $this->renderList($bot);
    }

    public function prevPage(Nutgram $bot): void
    {
        $this->page = max(1, $this->page - 1);
        $this->renderList($bot);
    }

    protected function renderList(Nutgram $bot): void
    {
        try {
            $result = $this->client()->listServers($this->page, 8);
        } catch (ProviderException $e) {
            $this->closeMenu("خطا در دریافت لیست سرورها:\n{$e->getMessage()}");
            $this->end();
            return;
        }

        $this->clearButtons();

        if (empty($result['items']) && $this->page === 1) {
            $this->menuText('هیچ سروری در این پنل یافت نشد.');
        } else {
            $this->menuText("سرورهای شما (صفحه {$this->page}):");

            foreach ($result['items'] as $server) {
                $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? '-';
                $statusIcon = match ($server['status'] ?? '') {
                    'active' => '🟢',
                    'off' => '🔴',
                    default => '⚪',
                };
                $flag = $this->client()->regionFlag($server['region']['slug'] ?? '');
                $regionName = $server['region']['name'] ?? $server['region']['slug'] ?? '-';

                $this->addButtonRow(InlineKeyboardButton::make(
                    "{$statusIcon} {$server['name']} | {$flag} {$regionName} | {$ip}",
                    callback_data: "{$server['id']}@showServer"
                ));
            }
        }

        $navRow = [];
        if ($this->page > 1) {
            $navRow[] = InlineKeyboardButton::make('⬅️ قبلی', callback_data: 'x@prevPage');
        }
        if ($result['has_more'] ?? false) {
            $navRow[] = InlineKeyboardButton::make('➡️ بعدی', callback_data: 'x@nextPage');
        }
        if (! empty($navRow)) {
            $this->addButtonRow(...$navRow);
        }

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToPanelChoice'));
        $this->showMenu();
    }

    public function backToPanelChoice(Nutgram $bot): void
    {
        $this->start($bot);
    }

    public function showServer(Nutgram $bot, string $data): void
    {
        $this->serverId = $data;
        $this->renderServerDetail($bot);
    }

    protected function renderServerDetail(Nutgram $bot): void
    {
        try {
            $server = $this->client()->getServer($this->serverId);
        } catch (ProviderException $e) {
            $this->closeMenu("خطا در دریافت اطلاعات سرور:\n{$e->getMessage()}");
            $this->end();
            return;
        }

        $ip = collect($server['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? '-';

        try {
            $reservedIp = collect($this->client()->listReservedIps($this->serverId))->pluck('ip')->implode(', ') ?: '-';
        } catch (ProviderException) {
            $reservedIp = '-';
        }

        $flag = $this->client()->regionFlag($server['region']['slug'] ?? '');
        $regionName = $server['region']['name'] ?? $server['region']['slug'] ?? '-';

        $size = $server['size'] ?? [];
        $size['slug'] ??= $server['size_slug'] ?? '';
        $diskGb = $size['disk'] ?? '-';
        $sizeLabel = isset($size['vcpus'], $size['memory'], $size['price_monthly'])
            ? $this->formatSizeLabel($size)
            : ($server['size_slug'] ?? '-');

        $password = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->value('root_password') ?? '-';

        $this->clearButtons();
        $this->menuText(
            $this->rtl(
                "🏷 نام: `{$server['name']}`\n".
                "⚙️ وضعیت: `{$server['status']}`\n".
                "🌐 آی‌پی: `{$ip}`\n".
                "➕ آی‌پی رزرو: `{$reservedIp}`\n".
                "🔑 پسورد روت: `{$password}`\n".
                "{$flag} دیتاسنتر: `{$regionName}`\n".
                "پلن: `{$sizeLabel}` | 💿 دیسک: `{$diskGb}GB`\n".
                "💿 سیستم‌عامل: `{$server['image']['distribution']} {$server['image']['name']}`"
            ),
            ['parse_mode' => 'Markdown']
        );

        // Power controls
        $this->addButtonGrid([
            InlineKeyboardButton::make('🔌 روشن کردن', callback_data: 'x@powerOn'),
            InlineKeyboardButton::make('⏻ خاموش کردن', callback_data: 'x@powerOff'),
            InlineKeyboardButton::make('🔁 ری‌استارت', callback_data: 'x@reboot'),
        ], perRow: 3);

        // Server configuration
        $this->addButtonGrid([
            InlineKeyboardButton::make('📈 ریسایز', callback_data: 'x@resizeMenu'),
            InlineKeyboardButton::make('🧱 ریبیلد', callback_data: 'x@rebuildMenu'),
            InlineKeyboardButton::make('🌐 آی‌پی رزرو', callback_data: 'x@reservedIpMenu'),
        ], perRow: 3);

        // VPN node management
        $this->addButtonGrid([
            InlineKeyboardButton::make('🧩 نود پاسارگارد', callback_data: 'x@confirmInstallNode'),
            InlineKeyboardButton::make('🔄 آپدیت وایرگارد', callback_data: 'x@updateWireguards'),
        ]);

        // "تغییر سرور" builds a fresh server with the same specs (+ same
        // node/WireGuard profile if any) and auto-deletes this one once it's
        // confirmed working — not destructive to THIS server until the
        // replacement is verified, so it's fine sharing a row with delete.
        $this->addButtonRow(
            InlineKeyboardButton::make('🗑 حذف سرور', callback_data: 'x@confirmDeleteServer'),
            InlineKeyboardButton::make('🔄 تغییر سرور', callback_data: 'x@replaceServer'),
        );

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت به لیست', callback_data: 'x@backToList'));
        $this->showMenu();
    }

    public function backToList(Nutgram $bot): void
    {
        $this->renderList($bot);
    }

    protected function dispatchAction(Nutgram $bot, string $ack, string $success, string $failure, callable $call): void
    {
        try {
            $action = $call($this->client());
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        $this->setCallbackQueryOptions(['text' => $ack]);

        // Unlike DigitalOcean, a Linode rebuild needs a fresh root password
        // set in the same request (it has no other way to preserve access) —
        // LinodeClient::rebuild() generates one and returns it here so it
        // doesn't go stale in our own records.
        if (! empty($action['root_password'])) {
            ServerSecret::where('panel_id', $this->panelId)
                ->where('provider_server_id', $this->serverId)
                ->update(['root_password' => $action['root_password']]);
        }

        if (! empty($action['id'])) {
            PollProviderActionJob::dispatch($this->panelId, $action['id'], $bot->chatId(), $success, $failure);
        }

        $this->renderServerDetail($bot);
    }

    public function powerOn(Nutgram $bot): void
    {
        $this->dispatchAction(
            $bot,
            'درخواست روشن کردن ارسال شد.',
            "✅ سرور روشن شد.",
            "❌ روشن کردن سرور ناموفق بود.",
            fn (ProviderClient $c) => $c->powerOn($this->serverId)
        );
    }

    public function powerOff(Nutgram $bot): void
    {
        $this->dispatchAction(
            $bot,
            'درخواست خاموش کردن ارسال شد.',
            "✅ سرور خاموش شد.",
            "❌ خاموش کردن سرور ناموفق بود.",
            fn (ProviderClient $c) => $c->powerOff($this->serverId)
        );
    }

    public function reboot(Nutgram $bot): void
    {
        $this->dispatchAction(
            $bot,
            'درخواست ری‌استارت ارسال شد.',
            "✅ سرور ری‌استارت شد.",
            "❌ ری‌استارت سرور ناموفق بود.",
            fn (ProviderClient $c) => $c->reboot($this->serverId)
        );
    }

    public function resizeMenu(Nutgram $bot): void
    {
        try {
            $server = $this->client()->getServer($this->serverId);

            if (($server['status'] ?? null) !== 'off') {
                $this->clearButtons();
                $this->menuText("برای تغییر پلن، سرور باید ابتدا خاموش شود.\nآیا الان خاموش شود؟");
                $this->addButtonRow(
                    InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToServer'),
                    InlineKeyboardButton::make('⏻ خاموش کن', callback_data: 'x@powerOff'),
                );
                $this->showMenu();
                return;
            }

            $sizes = $this->client()->sizes($server['region']['slug']);
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        usort($sizes, fn (array $a, array $b) => [$a['vcpus'], $a['memory'], (float) $a['price_monthly']]
            <=> [$b['vcpus'], $b['memory'], (float) $b['price_monthly']]);

        $this->clearButtons();
        $this->menuText('پلن جدید را انتخاب کنید:');

        $this->addButtonGrid(array_map(
            fn (array $s) => InlineKeyboardButton::make($this->formatSizeLabel($s), callback_data: "{$s['slug']}@doResize"),
            $sizes
        ), perRow: 1);

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToServer'));
        $this->showMenu();
    }

    public function doResize(Nutgram $bot, string $data): void
    {
        $this->dispatchAction(
            $bot,
            'درخواست تغییر پلن ارسال شد.',
            "✅ پلن سرور به {$data} تغییر کرد.",
            "❌ تغییر پلن ناموفق بود.",
            fn (ProviderClient $c) => $c->resize($this->serverId, $data, true)
        );
    }

    public function rebuildMenu(Nutgram $bot): void
    {
        try {
            $images = $this->onlyUbuntu($this->client()->images('distribution'));
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        $this->clearButtons();
        $this->menuText("⚠️ ریبیلد تمام اطلاعات روی سرور را پاک می‌کند.\nسیستم‌عامل جدید را انتخاب کنید:");

        $this->addButtonGrid(array_map(
            fn (array $img) => InlineKeyboardButton::make("💿 {$img['label']}", callback_data: "{$img['slug']}@confirmRebuild"),
            $images
        ));

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToServer'));
        $this->showMenu();
    }

    public function confirmRebuild(Nutgram $bot, string $data): void
    {
        $this->pendingImage = $data;

        $this->clearButtons();
        $this->menuText("⚠️ با نصب مجدد «{$data}»، تمام دیتای فعلی سرور پاک می‌شود.\nمطمئن هستید؟");
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToServer'),
            InlineKeyboardButton::make('✅ بله، ریبیلد کن', callback_data: 'yes@doRebuild'),
        );
        $this->showMenu();
    }

    public function doRebuild(Nutgram $bot): void
    {
        $this->dispatchAction(
            $bot,
            'درخواست ریبیلد ارسال شد.',
            '✅ ریبیلد سرور با موفقیت انجام شد.',
            '❌ ریبیلد سرور ناموفق بود.',
            fn (ProviderClient $c) => $c->rebuild($this->serverId, $this->pendingImage)
        );
    }

    /**
     * DigitalOcean has no "change the server's IP" operation — a droplet's main
     * IP is fixed for its lifetime. The only thing that can be added/replaced
     * is an extra "reserved IP" pointed at the droplet, so both cases are
     * handled by this single menu instead of two separate, confusing actions.
     */
    public function reservedIpMenu(Nutgram $bot): void
    {
        try {
            $current = $this->client()->listReservedIps($this->serverId);
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        if (empty($current)) {
            $this->allocateAndAssignReservedIp($bot);
            return;
        }

        $this->clearButtons();
        $this->menuText("آی‌پی رزرو فعلی سرور: {$current[0]['ip']}\nآیا می‌خواهید آن را با یک آی‌پی جدید جایگزین کنید؟");
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToServer'),
            InlineKeyboardButton::make('✅ بله، جایگزین کن', callback_data: 'yes@replaceReservedIp'),
        );
        $this->showMenu();
    }

    public function replaceReservedIp(Nutgram $bot): void
    {
        try {
            foreach ($this->client()->listReservedIps($this->serverId) as $reservedIp) {
                $this->client()->releaseReservedIp($reservedIp['ip']);
            }
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        $this->allocateAndAssignReservedIp($bot, ack: 'در حال جایگزینی آی‌پی رزرو...');
    }

    protected function allocateAndAssignReservedIp(Nutgram $bot, string $ack = 'در حال اختصاص آی‌پی رزرو...'): void
    {
        try {
            $server = $this->client()->getServer($this->serverId);
            $reserved = $this->client()->allocateReservedIp($server['region']['slug'], $this->serverId);
            $ip = $reserved['reserved_ip']['ip'] ?? null;

            if (! $ip) {
                throw new ProviderException('پاسخ نامعتبر از سرویس‌دهنده.');
            }

            $action = $this->client()->assignReservedIp($ip, $this->serverId);
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        $this->setCallbackQueryOptions(['text' => $ack]);

        if (! empty($action['id'])) {
            PollProviderActionJob::dispatch(
                $this->panelId,
                $action['id'],
                $bot->chatId(),
                "✅ آی‌پی رزرو {$ip} به سرور اختصاص یافت.",
                "❌ اختصاص آی‌پی {$ip} ناموفق بود.",
            );
        }

        $this->renderServerDetail($bot);
    }

    /**
     * Adds one button per saved WireGuard profile plus a "بدون وایرگارد"
     * skip option, each routed to $callback with the picked value as data
     * ('none' or the profile id).
     */
    protected function addProfileButtons(string $callback): void
    {
        foreach (WireguardProfile::ownedBy($this->ownerId)->orderBy('name')->get() as $profile) {
            $this->addButtonRow(InlineKeyboardButton::make(
                "🪪 {$profile->name}",
                callback_data: "{$profile->id}@{$callback}"
            ));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🚫 بدون وایرگارد', callback_data: "none@{$callback}"));
    }

    public function confirmInstallNode(Nutgram $bot): void
    {
        $this->clearButtons();
        $this->menuText(
            "🧩 این کار داکر را (در صورت نبود) روی سرور نصب می‌کند و یک نود پاسارگارد بالا می‌آورد.\n".
            'کدام پروفایل وایرگارد (هویت این سرور) روی آن فعال شود؟'
        );
        $this->addProfileButtons('chooseInstallProfile');
        $this->addButtonRow(InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToServer'));
        $this->showMenu();
    }

    public function chooseInstallProfile(Nutgram $bot, string $data): void
    {
        $this->pendingProfile = $data;

        $hasSecret = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->exists();

        if (! $hasSecret) {
            $this->closeMenu(
                "🧩 این کار داکر را (در صورت نبود) روی سرور نصب می‌کند و یک نود پاسارگارد بالا می‌آورد.\n".
                'پسورد روت این سرور ذخیره نشده (احتمالاً قبل از این قابلیت ساخته شده یا دستی عوض شده).'
            );
            $this->end();
            NodePasswordConversation::begin(
                $bot,
                $bot->userId(),
                $bot->chatId(),
                [$this->panelId, $this->serverId, 'install', $data]
            );
            return;
        }

        $this->clearButtons();
        $this->menuText(
            "🧩 این کار داکر را (در صورت نبود) روی سرور نصب می‌کند، یک نود پاسارگارد بالا می‌آورد و همه‌ی لوکیشن‌های وایرگارد ذخیره‌شده را با این پروفایل رویش فعال می‌کند.\n".
            'این عملیات چند دقیقه طول می‌کشد. ادامه بدهم؟'
        );
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToServer'),
            InlineKeyboardButton::make('✅ بله، نصب کن', callback_data: 'yes@installNode'),
        );
        $this->showMenu();
    }

    public function installNode(Nutgram $bot): void
    {
        ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->update(['wireguard_profile_id' => $this->pendingProfile === 'none' ? null : (int) $this->pendingProfile]);

        InstallPasarguardNodeJob::dispatch($this->panelId, $this->serverId, $bot->chatId());

        $this->closeMenu('⏳ درخواست نود کردن سرور ثبت شد. به محض اتمام نتیجه را برایتان ارسال می‌کنم.');
        $this->end();
    }

    public function updateWireguards(Nutgram $bot): void
    {
        $this->clearButtons();
        $this->menuText('کدام پروفایل وایرگارد (هویت این سرور) روی آن فعال شود؟');
        $this->addProfileButtons('chooseUpdateProfile');
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToServer'));
        $this->showMenu();
    }

    public function chooseUpdateProfile(Nutgram $bot, string $data): void
    {
        $hasSecret = ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->exists();

        if (! $hasSecret) {
            $this->closeMenu('پسورد روت این سرور ذخیره نشده (احتمالاً قبل از این قابلیت ساخته شده یا دستی عوض شده).');
            $this->end();
            NodePasswordConversation::begin(
                $bot,
                $bot->userId(),
                $bot->chatId(),
                [$this->panelId, $this->serverId, 'update_wireguards', $data]
            );
            return;
        }

        ServerSecret::where('panel_id', $this->panelId)
            ->where('provider_server_id', $this->serverId)
            ->update(['wireguard_profile_id' => $data === 'none' ? null : (int) $data]);

        UpdateWireguardsJob::dispatch($this->panelId, $this->serverId, $bot->chatId());

        $this->setCallbackQueryOptions(['text' => 'درخواست بروزرسانی وایرگاردها ثبت شد.']);
        $this->renderServerDetail($bot);
    }

    public function replaceServer(Nutgram $bot): void
    {
        $this->endWithoutClosing();
        ReplaceServerConversation::begin($bot, $bot->userId(), $bot->chatId(), [$this->panelId, $this->serverId]);
    }

    public function confirmDeleteServer(Nutgram $bot): void
    {
        $this->clearButtons();
        $this->menuText('⚠️ آیا از حذف کامل این سرور مطمئن هستید؟ این عملیات غیرقابل بازگشت است.');
        $this->addButtonRow(
            InlineKeyboardButton::make('🔙 انصراف', callback_data: 'x@backToServer'),
            InlineKeyboardButton::make('✅ بله، حذف کن', callback_data: 'yes@doDeleteServer'),
        );
        $this->showMenu();
    }

    public function doDeleteServer(Nutgram $bot): void
    {
        try {
            $this->client()->deleteServer($this->serverId);
        } catch (ProviderException $e) {
            $this->setCallbackQueryOptions(['text' => "خطا: {$e->getMessage()}", 'show_alert' => true]);
            return;
        }

        $this->setCallbackQueryOptions(['text' => 'سرور حذف شد.']);
        $this->renderList($bot);
    }

    public function backToServer(Nutgram $bot): void
    {
        $this->renderServerDetail($bot);
    }
}
