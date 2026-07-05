<?php

namespace Tests\Feature;

use App\Models\WireguardConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class WireguardSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_wireguard_conversation_stores_config(): void
    {
        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $wgConfig = "[Interface]\nAddress = 10.14.0.2/16\nPrivateKey = fakekey=\nDNS = 1.1.1.1\n\ntable = off\n\n[Peer]\nPublicKey = fakepub=\nAllowedIPs = 0.0.0.0/0\nEndpoint = 1.2.3.4:51820";

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "وایرگاردها" (only x-prefixed button before cancel)
        $bot->hearCallbackQueryData('x')->reply(); // "افزودن وایرگارد" (empty-list screen: only add + cancel)
        $bot->hearText('eu1')->reply();
        $bot->hearText($wgConfig)->reply();

        $this->assertDatabaseHas('wireguard_configs', ['name' => 'eu1']);
        $this->assertSame($wgConfig, WireguardConfig::first()->config);
    }

    public function test_invalid_wireguard_config_is_rejected(): void
    {
        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearText('bad1')->reply();
        $bot->hearText('this is not a wireguard config')->reply();

        $this->assertDatabaseMissing('wireguard_configs', ['name' => 'bad1']);
    }
}
