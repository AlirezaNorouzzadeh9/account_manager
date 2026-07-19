<?php

namespace Tests\Feature;

use App\Models\WireguardLocation;
use App\Models\WireguardProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class WireguardTest extends TestCase
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

    public function test_add_location_stores_country_ip_and_server_public_key(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها"
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن لوکیشن" (first x button, empty menu)
        $bot->hearText('germany')->reply();
        $bot->hearText('DE')->reply();
        $bot->hearText('89.249.73.213')->reply();
        $bot->hearText('9wZOjtwuKEc0GBcvc3xJQ4Kjo8G3EMXu6zJRzbanOjc=')->reply();

        $location = WireguardLocation::first();
        $this->assertNotNull($location);
        $this->assertSame('germany', $location->name);
        $this->assertSame('DE', $location->country);
        $this->assertSame('89.249.73.213', $location->ip);
        $this->assertSame('9wZOjtwuKEc0GBcvc3xJQ4Kjo8G3EMXu6zJRzbanOjc=', $location->server_public_key);
        $this->assertSame('🇩🇪', $location->flag());

        // After saving, the new location's OWN detail screen opens directly
        // (not the full list) with a save confirmation folded into it.
        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('ذخیره شد', $body['text']);
        $this->assertStringContainsString('germany', $body['text']);
    }

    public function test_add_location_skips_country_when_dash_is_sent(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearText('al')->reply();
        $bot->hearText('-')->reply();
        $bot->hearText('89.249.73.213')->reply();
        $bot->hearText('9wZOjtwuKEc0GBcvc3xJQ4Kjo8G3EMXu6zJRzbanOjc=')->reply();

        $this->assertNull(WireguardLocation::first()->country);
    }

    public function test_add_location_rejects_a_country_code_that_is_not_two_letters(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearText('al')->reply();
        $bot->hearText('Germany')->reply(); // not a 2-letter code — rejected
        $bot->hearText('de')->reply(); // lowercase is fine, normalized to uppercase
        $bot->hearText('89.249.73.213')->reply();
        $bot->hearText('9wZOjtwuKEc0GBcvc3xJQ4Kjo8G3EMXu6zJRzbanOjc=')->reply();

        $this->assertSame('DE', WireguardLocation::first()->country);
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
        $bot->hearText('-')->reply(); // skip country
        $bot->hearText('not-an-ip')->reply();

        $this->assertDatabaseMissing('wireguard_locations', ['name' => 'bad1']);
    }

    public function test_duplicate_location_name_is_rejected(): void
    {
        WireguardLocation::create([
            'name' => 'germany',
            'ip' => '1.2.3.4',
            'server_public_key' => 'pub',
            'created_by' => 555,
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
            'created_by' => 555,
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData((string) $location->id)->reply(); // showLocation
        $bot->hearCallbackQueryData('x@')->reply(); // "🗑 حذف لوکیشن" (2nd x-prefixed button, after country)
        $bot->hearCallbackQueryData('yes')->reply();

        $this->assertNull($location->fresh());
    }

    public function test_set_location_country_updates_it(): void
    {
        $location = WireguardLocation::create([
            'name' => 'al',
            'ip' => '1.2.3.4',
            'server_public_key' => 'pub',
            'created_by' => 555,
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData((string) $location->id)->reply(); // showLocation
        $bot->hearCallbackQueryData('x')->reply(); // "🌍 تنظیم کشور" (1st x-prefixed button)
        $bot->hearText('AL')->reply();

        $this->assertSame('AL', $location->fresh()->country);
        $this->assertSame('🇦🇱', $location->fresh()->flag());
    }

    public function test_add_profile_stores_name_and_private_key(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "🪪 پروفایل‌ها" (2nd x-prefixed button on settings menu, direct)
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن پروفایل" (first x button, empty menu)
        $bot->hearText('server-1')->reply();
        $bot->hearText('fake-private-key')->reply();

        $profile = WireguardProfile::first();
        $this->assertNotNull($profile);
        $this->assertSame('server-1', $profile->name);
        $this->assertSame('fake-private-key', $profile->private_key);

        // After saving, the new profile's OWN detail screen opens directly
        // (not the full list) with a save confirmation folded into it.
        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('ذخیره شد', $body['text']);
        $this->assertStringContainsString('server-1', $body['text']);
    }

    public function test_deleting_a_profile_removes_it_and_clears_it_from_servers(): void
    {
        $profile = WireguardProfile::create(['name' => 'server-1', 'private_key' => 'fake-key', 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "🪪 پروفایل‌ها" (direct from settings menu)
        $bot->hearCallbackQueryData((string) $profile->id)->reply(); // showProfile
        $bot->hearCallbackQueryData('x@@@')->reply(); // "🗑 حذف پروفایل" (4th x-prefixed button, after core_id + own ip)
        $bot->hearCallbackQueryData('yes')->reply();

        $this->assertNull($profile->fresh());
    }

    public function test_set_core_id_updates_the_profile(): void
    {
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-key', 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "🪪 پروفایل‌ها" (direct from settings menu)
        $bot->hearCallbackQueryData((string) $profile->id)->reply(); // showProfile
        $bot->hearCallbackQueryData('x@')->reply(); // "🧩 تنظیم آیدی هسته" (2nd x-prefixed button)
        $bot->hearText('268')->reply();

        $this->assertSame(268, $profile->fresh()->core_id);
    }

    public function test_set_core_id_rejects_non_numeric_input(): void
    {
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-key', 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "🪪 پروفایل‌ها" (direct from settings menu)
        $bot->hearCallbackQueryData((string) $profile->id)->reply(); // showProfile
        $bot->hearCallbackQueryData('x@')->reply(); // "🧩 تنظیم آیدی هسته"
        $bot->hearText('not-a-number')->reply();

        $this->assertNull($profile->fresh()->core_id);
    }

    public function test_sending_zero_clears_the_profiles_core_id(): void
    {
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-key', 'core_id' => 268, 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "🪪 پروفایل‌ها" (direct from settings menu)
        $bot->hearCallbackQueryData((string) $profile->id)->reply(); // showProfile
        $bot->hearCallbackQueryData('x@')->reply(); // "🧩 تنظیم آیدی هسته"
        $bot->hearText('0')->reply();

        $this->assertNull($profile->fresh()->core_id);
    }

    public function test_set_own_ip_updates_the_profile(): void
    {
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-key', 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "🪪 پروفایل‌ها" (direct from settings menu)
        $bot->hearCallbackQueryData((string) $profile->id)->reply(); // showProfile
        $bot->hearCallbackQueryData('x@@')->reply(); // "📍 تنظیم آی‌پی اصلی" (3rd x-prefixed button)
        $bot->hearText('91.107.156.48')->reply();

        $this->assertSame('91.107.156.48', $profile->fresh()->own_ip);
    }

    public function test_set_own_ip_rejects_an_invalid_ip(): void
    {
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-key', 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply();
        $bot->hearCallbackQueryData((string) $profile->id)->reply();
        $bot->hearCallbackQueryData('x@@')->reply(); // "📍 تنظیم آی‌پی اصلی"
        $bot->hearText('not-an-ip')->reply();

        $this->assertNull($profile->fresh()->own_ip);
    }

    public function test_sending_dash_clears_the_profiles_own_ip(): void
    {
        $profile = WireguardProfile::create([
            'name' => 'germany', 'private_key' => 'fake-key', 'created_by' => 555, 'own_ip' => '1.2.3.4',
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply();
        $bot->hearCallbackQueryData((string) $profile->id)->reply();
        $bot->hearCallbackQueryData('x@@')->reply(); // "📍 تنظیم آی‌پی اصلی"
        $bot->hearText('-')->reply();

        $this->assertNull($profile->fresh()->own_ip);
    }
}
