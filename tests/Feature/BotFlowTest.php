<?php

namespace Tests\Feature;

use App\Jobs\ConnectServerWireguardsJob;
use App\Jobs\CreateServerReadyJob;
use App\Models\ConnectedServer;
use App\Models\Panel;
use App\Models\WireguardProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class BotFlowTest extends TestCase
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

    public function test_add_panel_conversation_creates_panel(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response([
                'account' => ['email' => 'owner@example.com', 'uuid' => 'abc-uuid'],
            ]),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('digitalocean')->reply();
        $bot->hearText('My DO Panel')->reply();
        $bot->hearText('dop_v1_fake_token_for_testing')->reply();

        $this->assertDatabaseHas('panels', [
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
        ]);

        $panel = Panel::first();
        $this->assertSame('owner@example.com', $panel->meta['email']);
        $this->assertSame('dop_v1_fake_token_for_testing', $panel->api_token);
    }

    public function test_add_panel_conversation_creates_a_linode_panel_with_the_linode_token_url(): void
    {
        Http::fake([
            'api.linode.com/v4/account' => Http::response(['email' => 'owner@example.com']),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('linode')->reply();
        $bot->hearText('My Linode Panel')->reply();

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('cloud.linode.com/profile/tokens', $body['text']);

        $bot->hearText('linode_fake_token_for_testing')->reply();

        $this->assertDatabaseHas('panels', [
            'name' => 'My Linode Panel',
            'provider' => 'linode',
        ]);

        $panel = Panel::where('name', 'My Linode Panel')->first();
        $this->assertSame('owner@example.com', $panel->meta['email']);
    }

    public function test_add_panel_conversation_creates_a_vultr_panel_with_the_vultr_token_url(): void
    {
        Http::fake([
            'api.vultr.com/v2/account' => Http::response([
                'account' => ['email' => 'owner@example.com'],
            ]),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('vultr')->reply();
        $bot->hearText('My Vultr Panel')->reply();

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('my.vultr.com/settings', $body['text']);

        $bot->hearText('vultr_fake_token_for_testing')->reply();

        $this->assertDatabaseHas('panels', [
            'name' => 'My Vultr Panel',
            'provider' => 'vultr',
        ]);

        $panel = Panel::where('name', 'My Vultr Panel')->first();
        $this->assertSame('owner@example.com', $panel->meta['email']);
    }

    public function test_rename_panel_updates_name(): void
    {
        $panel = Panel::create([
            'name' => 'Old Name',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData((string) $panel->id)->reply(); // showPanel
        $bot->hearCallbackQueryData((string) $panel->id)->reply(); // "✏️ ویرایش نام" (first id-based button on this screen)
        $bot->hearText('New Name')->reply();

        $this->assertSame('New Name', $panel->fresh()->name);
    }

    public function test_create_server_conversation_dispatches_ready_job(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
            'created_by' => 555,
        ]);

        Http::fake([
            'api.digitalocean.com/v2/regions*' => Http::response([
                'regions' => [
                    ['slug' => 'nyc1', 'name' => 'New York 1', 'available' => true, 'sizes' => [], 'features' => []],
                ],
            ]),
            'api.digitalocean.com/v2/sizes*' => Http::response([
                'sizes' => [[
                    'slug' => 's-1vcpu-1gb', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25,
                    'transfer' => 1, 'price_monthly' => 6, 'price_hourly' => 0.009,
                    'regions' => ['nyc1'], 'available' => true,
                ]],
            ]),
            'api.digitalocean.com/v2/images*' => Http::response([
                'images' => [[
                    'id' => 1, 'name' => '24.04 x64', 'distribution' => 'Ubuntu',
                    'slug' => 'ubuntu-24-04-x64', 'public' => true, 'regions' => ['nyc1'],
                ]],
            ]),
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 123, 'name' => 'my-server-1'],
                'links' => ['actions' => [['id' => 999, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:create')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('nyc1')->reply();
        $bot->hearCallbackQueryData('s-1vcpu-1gb')->reply();
        $bot->hearCallbackQueryData('ubuntu-24-04-x64')->reply();
        $bot->hearText('my-server-1')->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        Queue::assertPushed(CreateServerReadyJob::class);
    }

    public function test_connect_server_conversation_dispatches_wireguards_job(): void
    {
        Queue::fake();

        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-private-key', 'created_by' => 555]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:connect')->reply();
        $bot->hearText('203.0.113.10')->reply();
        $bot->hearText('root')->reply();
        $bot->hearText('super-secret-pass')->reply();
        $bot->hearCallbackQueryData("{$profile->id}")->reply(); // chooseProfile
        $bot->hearCallbackQueryData('yes')->reply(); // confirm

        Queue::assertPushed(ConnectServerWireguardsJob::class, function (ConnectServerWireguardsJob $job) {
            return $this->privateProp($job, 'host') === '203.0.113.10'
                && $this->privateProp($job, 'username') === 'root'
                && $this->privateProp($job, 'password') === 'super-secret-pass'
                && $this->privateProp($job, 'wireguardPrivateKey') === 'fake-private-key';
        });

        $connected = ConnectedServer::where('host', '203.0.113.10')->first();
        $this->assertNotNull($connected);
        $this->assertSame('root', $connected->username);
        $this->assertSame('super-secret-pass', $connected->password);
        $this->assertSame($profile->id, $connected->wireguard_profile_id);
        $this->assertSame(555, $connected->created_by);
    }

    public function test_connect_server_conversation_upserts_by_host_on_reconnect(): void
    {
        Queue::fake();

        $profileA = WireguardProfile::create(['name' => 'germany', 'private_key' => 'key-a', 'created_by' => 555]);
        $profileB = WireguardProfile::create(['name' => 'france', 'private_key' => 'key-b', 'created_by' => 555]);

        ConnectedServer::create([
            'host' => '203.0.113.20',
            'username' => 'root',
            'password' => 'old-pass',
            'wireguard_profile_id' => $profileA->id,
            'created_by' => 555,
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:connect')->reply();
        $bot->hearText('203.0.113.20')->reply();
        $bot->hearText('root')->reply();
        $bot->hearText('new-pass')->reply();
        $bot->hearCallbackQueryData("{$profileB->id}")->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        $this->assertSame(1, ConnectedServer::where('host', '203.0.113.20')->count());

        $connected = ConnectedServer::where('host', '203.0.113.20')->first();
        $this->assertSame('new-pass', $connected->password);
        $this->assertSame($profileB->id, $connected->wireguard_profile_id);
    }

    public function test_connect_server_conversation_rejects_invalid_ip(): void
    {
        Queue::fake();

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:connect')->reply();
        $bot->hearText('not-an-ip')->reply();

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('آی‌پی نامعتبر', $body['text']);

        Queue::assertNotPushed(ConnectServerWireguardsJob::class);
    }

    protected function privateProp(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
