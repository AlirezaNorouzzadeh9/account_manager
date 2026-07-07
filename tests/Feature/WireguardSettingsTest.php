<?php

namespace Tests\Feature;

use App\Models\WireguardLocation;
use App\Models\WireguardSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class WireguardSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function bot(): FakeNutgram
    {
        config(['bot.admins' => ['555']]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));

        return $bot;
    }

    public function test_add_location_stores_ip_keys_and_applies_to_every_server(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها"
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن لوکیشن" (first x button, empty menu)
        $bot->hearText('germany')->reply();
        $bot->hearText('89.249.73.213')->reply();
        $bot->hearText('9wZOjtwuKEc0GBcvc3xJQ4Kjo8G3EMXu6zJRzbanOjc=')->reply();

        $location = WireguardLocation::first();
        $this->assertNotNull($location);
        $this->assertSame('germany', $location->name);
        $this->assertSame('89.249.73.213', $location->ip);
        $this->assertSame('9wZOjtwuKEc0GBcvc3xJQ4Kjo8G3EMXu6zJRzbanOjc=', $location->server_public_key);
    }

    public function test_back_button_during_add_location_cancels_and_returns_to_list(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها"
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن لوکیشن"
        $bot->hearText('germany')->reply();
        $bot->hearCallbackQueryData('back')->reply(); // "🔙 بازگشت" instead of the ip

        $this->assertNull(WireguardLocation::where('name', 'germany')->first());

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('هیچ لوکیشن وایرگاردی', $body['text']);
    }

    public function test_invalid_ip_is_rejected(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن لوکیشن"
        $bot->hearText('bad1')->reply();
        $bot->hearText('not-an-ip')->reply();

        $this->assertDatabaseMissing('wireguard_locations', ['name' => 'bad1']);
    }

    public function test_duplicate_location_name_is_rejected(): void
    {
        WireguardLocation::create([
            'name' => 'germany',
            'ip' => '1.2.3.4',
            'server_public_key' => 'pub',
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "➕ افزودن لوکیشن" (2nd x-prefixed button now that a location exists)
        $bot->hearText('germany')->reply();

        $this->assertSame(1, WireguardLocation::where('name', 'germany')->count());
    }

    public function test_deleting_a_location_removes_it(): void
    {
        $location = WireguardLocation::create([
            'name' => 'germany',
            'ip' => '1.2.3.4',
            'server_public_key' => 'pub',
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData((string) $location->id)->reply(); // showLocation
        $bot->hearCallbackQueryData('x')->reply(); // "🗑 حذف لوکیشن" (1st x-prefixed button now that revealPrivateKey is gone)
        $bot->hearCallbackQueryData('yes')->reply();

        $this->assertNull($location->fresh());
    }

    public function test_editing_settings_saves_shared_fields(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها"
        $bot->hearCallbackQueryData('x@')->reply(); // "⚙️ تنظیمات پایه" (2nd x-prefixed button, empty menu)
        $bot->hearText('10.14.0.2/16')->reply();
        $bot->hearText('162.252.172.57, 149.154.159.92')->reply();
        $bot->hearText('0.0.0.0/0')->reply();
        $bot->hearText('51820')->reply();
        $bot->hearText('fake-shared-private-key')->reply();

        $settings = WireguardSettings::current();
        $this->assertSame('10.14.0.2/16', $settings->address);
        $this->assertSame('162.252.172.57, 149.154.159.92', $settings->dns);
        $this->assertSame('0.0.0.0/0', $settings->allowed_ips);
        $this->assertSame(51820, $settings->port);
        $this->assertSame('fake-shared-private-key', $settings->private_key);
    }
}
