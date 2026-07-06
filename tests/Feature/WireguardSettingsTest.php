<?php

namespace Tests\Feature;

use App\Models\WireguardConfig;
use App\Models\WireguardProfile;
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

    protected function wgConfig(): string
    {
        return "[Interface]\nAddress = 10.14.0.2/16\nPrivateKey = fakekey=\nDNS = 1.1.1.1\n\ntable = off\n\n".
            "[Peer]\nPublicKey = fakepub=\nAllowedIPs = 0.0.0.0/0\nEndpoint = 1.2.3.4:51820";
    }

    public function test_add_wireguard_config_is_unassigned_until_added_to_a_profile(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        // Add a config from the top-level menu — no profile involved yet.
        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها"
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن وایرگارد" (first x button, empty menu)
        $bot->hearText('eu1')->reply();
        $bot->hearText($this->wgConfig())->reply();

        $config = WireguardConfig::first();
        $this->assertNotNull($config);
        $this->assertSame('eu1', $config->name);
        $this->assertNull($config->wireguard_profile_id);
    }

    public function test_selecting_a_config_for_a_profile_assigns_it_and_toggling_again_unassigns(): void
    {
        $config = WireguardConfig::create(['name' => 'eu1', 'config' => $this->wgConfig()]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها"

        // Create a profile: with 1 unassigned config + 0 profiles, buttons
        // are ["بدون پروفایل" (literal "none"), addConfigTopLevel (x), addProfile (x@), backToSettings (x@@)].
        $bot->hearCallbackQueryData('x@')->reply(); // "➕ افزودن پروفایل جدید"
        $bot->hearText('Profile 1')->reply();

        $profile = WireguardProfile::first();
        $this->assertNotNull($profile);

        // Open the profile, then its config selector, then toggle the config in.
        $bot->hearCallbackQueryData((string) $profile->id)->reply(); // showProfile
        $bot->hearCallbackQueryData('x')->reply(); // "🧩 انتخاب کانفیگ‌ها" (only x button on this screen)
        $bot->hearCallbackQueryData((string) $config->id)->reply(); // toggle config into this profile

        $this->assertSame($profile->id, $config->fresh()->wireguard_profile_id);

        // Toggling the same config again removes it from the profile.
        $bot->hearCallbackQueryData((string) $config->id)->reply();
        $this->assertNull($config->fresh()->wireguard_profile_id);
    }

    public function test_invalid_wireguard_config_is_rejected(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن وایرگارد"
        $bot->hearText('bad1')->reply();
        $bot->hearText('this is not a wireguard config')->reply();

        $this->assertDatabaseMissing('wireguard_configs', ['name' => 'bad1']);
    }
}
