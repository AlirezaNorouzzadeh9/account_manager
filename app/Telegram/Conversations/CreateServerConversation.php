<?php

namespace App\Telegram\Conversations;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Jobs\CreateServerReadyJob;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderManager;
use App\Telegram\Support\Cancellable;
use App\Telegram\Support\GridButtons;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Conversations\InlineMenu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

class CreateServerConversation extends InlineMenu
{
    use Cancellable;
    use GridButtons;

    protected ?int $panelId = null;
    protected ?string $region = null;
    protected ?string $regionLabel = null;
    protected ?string $size = null;
    protected ?string $image = null;
    protected ?string $imageLabel = null;
    protected ?string $hostname = null;
    protected array $regionLabels = [];
    protected array $imageLabels = [];

    public function start(Nutgram $bot): void
    {
        $panels = Panel::query()->active()->get();

        $this->clearButtons();

        if ($panels->isEmpty()) {
            $this->menuText("هیچ پنل فعالی وجود ندارد.\nابتدا از منوی «پنل‌های من» یک پنل اضافه کنید.");
            $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@cancel'));
            $this->showMenu();
            return;
        }

        $this->menuText('برای ساخت سرور، پنل مورد نظر را انتخاب کنید:');

        foreach ($panels as $panel) {
            $this->addButtonRow(InlineKeyboardButton::make(
                "{$panel->name} ({$panel->provider->label()})",
                callback_data: "{$panel->id}@choosePanel"
            ));
        }

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@cancel'));
        $this->showMenu();
    }

    public function choosePanel(Nutgram $bot, string $data): void
    {
        $this->panelId = (int) $data;
        $this->showRegions($bot);
    }

    public function backToPanels(Nutgram $bot): void
    {
        $this->start($bot);
    }

    protected function showRegions(Nutgram $bot): void
    {
        $panel = Panel::findOrFail($this->panelId);

        try {
            $regions = ProviderManager::forPanel($panel)->regions();
        } catch (ProviderException $e) {
            $this->closeMenu("خطا در دریافت لیست دیتاسنترها:\n{$e->getMessage()}");
            $this->end();
            return;
        }

        $this->regionLabels = array_column($regions, 'label', 'slug');

        $this->clearButtons();
        $this->menuText('کدام دیتاسنتر (لوکیشن) را می‌خواهید؟');

        $this->addButtonGrid(array_map(
            fn (array $r) => InlineKeyboardButton::make($r['label'], callback_data: "{$r['slug']}@chooseRegion"),
            $regions
        ));

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToPanels'));
        $this->showMenu();
    }

    public function chooseRegion(Nutgram $bot, string $data): void
    {
        $this->region = $data;
        $this->regionLabel = $this->regionLabels[$data] ?? $data;
        $this->showSizes($bot);
    }

    public function backToRegions(Nutgram $bot): void
    {
        $this->showRegions($bot);
    }

    protected function showSizes(Nutgram $bot): void
    {
        $panel = Panel::findOrFail($this->panelId);

        try {
            $sizes = ProviderManager::forPanel($panel)->sizes($this->region);
        } catch (ProviderException $e) {
            $this->closeMenu("خطا در دریافت لیست پلن‌ها:\n{$e->getMessage()}");
            $this->end();
            return;
        }

        $this->clearButtons();
        $this->menuText('پلن (سایز) سرور را انتخاب کنید:');

        $this->addButtonGrid(array_map(function (array $s) {
            $label = sprintf(
                '%s | %dvCPU/%dMB | $%s',
                $s['slug'],
                $s['vcpus'],
                $s['memory'],
                $s['price_monthly']
            );

            return InlineKeyboardButton::make($label, callback_data: "{$s['slug']}@chooseSize");
        }, $sizes), perRow: 1);

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToRegions'));
        $this->showMenu();
    }

    public function chooseSize(Nutgram $bot, string $data): void
    {
        $this->size = $data;
        $this->showImages($bot);
    }

    public function backToSizes(Nutgram $bot): void
    {
        $this->showSizes($bot);
    }

    protected function showImages(Nutgram $bot): void
    {
        $panel = Panel::findOrFail($this->panelId);

        try {
            $images = ProviderManager::forPanel($panel)->images('distribution');
        } catch (ProviderException $e) {
            $this->closeMenu("خطا در دریافت لیست سیستم‌عامل‌ها:\n{$e->getMessage()}");
            $this->end();
            return;
        }

        $this->imageLabels = array_column($images, 'label', 'slug');

        $this->clearButtons();
        $this->menuText('سیستم‌عامل را انتخاب کنید:');

        $this->addButtonGrid(array_map(
            fn (array $img) => InlineKeyboardButton::make($img['label'], callback_data: "{$img['slug']}@chooseImage"),
            $images
        ));

        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToSizes'));
        $this->showMenu();
    }

    public function chooseImage(Nutgram $bot, string $data): void
    {
        $this->image = $data;
        $this->imageLabel = $this->imageLabels[$data] ?? $data;
        $this->closeMenu("نام (hostname) سرور را ارسال کنید (مثلاً: my-server-1):");
        $this->next('receiveHostname');
    }

    public function receiveHostname(Nutgram $bot): void
    {
        $name = trim((string) $bot->message()?->text);

        if (! preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-.]{0,61}[a-zA-Z0-9])?$/', $name)) {
            $bot->sendMessage('نام نامعتبر است. فقط حروف انگلیسی، عدد، خط‌تیره و نقطه مجاز است. دوباره ارسال کنید یا /cancel را بزنید:');
            return;
        }

        $this->hostname = $name;

        $this->clearButtons();
        $this->menuText(
            "خلاصه سرور جدید:\n".
            "🏷 نام: {$this->hostname}\n".
            "📍 دیتاسنتر: {$this->regionLabel}\n".
            "💽 پلن: {$this->size}\n".
            "💿 سیستم‌عامل: {$this->imageLabel}\n\n".
            'ساخته شود؟'
        );
        $this->addButtonRow(InlineKeyboardButton::make('✅ بله، بساز', callback_data: 'yes@confirm'));
        $this->addButtonRow(InlineKeyboardButton::make('🔙 بازگشت', callback_data: 'x@backToImages'));
        $this->showMenu();
    }

    public function backToImages(Nutgram $bot): void
    {
        $this->showImages($bot);
    }

    public function confirm(Nutgram $bot): void
    {
        $panel = Panel::findOrFail($this->panelId);
        $password = Str::password(20, symbols: false);

        $userData = "#cloud-config\nchpasswd:\n  expire: false\n  list: |\n    root:{$password}\nssh_pwauth: true\n";

        try {
            $result = ProviderManager::forPanel($panel)->createServer([
                'name' => $this->hostname,
                'region' => $this->region,
                'size' => $this->size,
                'image' => $this->image,
                'monitoring' => true,
                'ipv6' => true,
                'user_data' => $userData,
            ]);
        } catch (ProviderException $e) {
            $this->closeMenu("❌ ساخت سرور ناموفق بود:\n{$e->getMessage()}");
            $this->end();
            return;
        }

        $actionId = $result['links']['actions'][0]['id'] ?? null;
        $serverId = $result['droplet']['id'] ?? null;
        $credentials = "👤 کاربر: root\n🔑 رمز عبور: {$password}";

        if ($serverId) {
            ServerSecret::updateOrCreate(
                ['panel_id' => $panel->id, 'provider_server_id' => $serverId],
                ['root_password' => $password]
            );
        }

        if ($actionId) {
            CreateServerReadyJob::dispatch(
                $panel->id,
                $actionId,
                $bot->chatId(),
                $this->hostname,
                $credentials,
            );
        }

        $this->closeMenu(
            "🚀 درخواست ساخت سرور «{$this->hostname}» ثبت شد.\n".
            'به محض آماده شدن، آی‌پی، اطلاعات ورود و نتیجه‌ی پینگ از سرورهای ایران برایتان ارسال می‌شود.'
        );
        $this->end();
    }
}
