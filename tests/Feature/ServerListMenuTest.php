<?php

namespace Tests\Feature;

use App\Jobs\PollProviderActionJob;
use App\Jobs\ServerPingCheckJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class ServerListMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_list_reboot_flow(): void
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

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
            'api.digitalocean.com/v2/droplets/42' => Http::response(['droplet' => $droplet]),
            'api.digitalocean.com/v2/droplets/42/actions' => Http::response([
                'action' => ['id' => 555, 'status' => 'in-progress', 'type' => 'reboot'],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('42')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // first grid button = powerOn

        Queue::assertPushed(PollProviderActionJob::class);
    }

    public function test_server_list_shows_datacenter_for_each_server(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();

        // The very last history entry after a callback tap is always the
        // automatic answerCallbackQuery (no "reply_markup" field) — find the
        // last actual sendMessage/editMessageText body instead.
        $markup = null;

        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['reply_markup'])) {
                $markup = $body['reply_markup'];
                break;
            }
        }

        $this->assertStringContainsString('New York 1', json_encode($markup, JSON_UNESCAPED_UNICODE));
    }

    public function test_check_ping_button_starts_a_ping_and_dispatches_the_check_job(): void
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

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
            'api.digitalocean.com/v2/droplets/42' => Http::response(['droplet' => $droplet]),
            'check-host.net/check-ping*' => Http::response([
                'ok' => 1,
                'request_id' => 'fake-request-id',
                'nodes' => [],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('42')->reply();
        $bot->hearCallbackQueryData('x@@@@@@')->reply(); // 7th grid button = checkPing

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'check-ping')
            && str_contains((string) $request->url(), 'host=1.2.3.4'));

        Queue::assertPushed(ServerPingCheckJob::class, function (ServerPingCheckJob $job) {
            $ref = new \ReflectionProperty($job, 'ip');
            $ref->setAccessible(true);

            return $ref->getValue($job) === '1.2.3.4';
        });
    }

    public function test_rebuild_persists_the_new_root_password_through_the_encrypted_cast(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My Linode Panel',
            'provider' => 'linode',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $secret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '42',
            'root_password' => 'old-plaintext-password',
        ]);

        $instance = [
            'id' => 42,
            'label' => 'sweden',
            'status' => 'running',
            'ipv4' => ['172.234.111.99'],
            'region' => 'se-sto',
            'type' => 'g6-dedicated-2',
            'image' => 'linode/ubuntu22.04',
        ];

        Http::fake([
            'api.linode.com/v4/linode/instances?*' => Http::response(['data' => [$instance], 'pages' => 1]),
            'api.linode.com/v4/linode/instances/42' => Http::response($instance),
            'api.linode.com/v4/linode/instances/42/ips' => Http::response(['ipv4' => ['public' => []]]),
            'api.linode.com/v4/linode/types/g6-dedicated-2' => Http::response([]),
            'api.linode.com/v4/images' => Http::response([
                'data' => [['id' => 'linode/ubuntu22.04', 'label' => 'Ubuntu 22.04 LTS']],
            ]),
            'api.linode.com/v4/linode/instances/42/rebuild' => Http::response(['id' => 42]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('42')->reply();
        $bot->hearCallbackQueryData('x@@@@')->reply(); // 5th grid button = rebuildMenu
        $bot->hearCallbackQueryData('linode/ubuntu22.04')->reply(); // confirmRebuild
        $bot->hearCallbackQueryData('yes')->reply(); // doRebuild

        $stored = $secret->fresh();
        $raw = $stored->getRawOriginal('root_password');
        $envelope = json_decode(base64_decode($raw), true);

        // A mass update() bypasses the 'encrypted' cast and writes the
        // plaintext straight to the column — asserting the raw column is a
        // valid Laravel encryption envelope (not the plaintext itself) is
        // what actually catches that regression.
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('iv', $envelope);
        $this->assertArrayHasKey('value', $envelope);
        $this->assertArrayHasKey('mac', $envelope);

        $this->assertSame(20, strlen($stored->root_password));
        $this->assertNotSame('old-plaintext-password', $stored->root_password);
    }

    protected function deleteServerPanelAndSecret(?int $wireguardProfileId = null): array
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet], 'links' => [], 'meta' => ['total' => 1],
            ]),
            'api.digitalocean.com/v2/droplets/42' => Http::response(['droplet' => $droplet]),
        ]);

        $secret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 42,
            'root_password' => 'pw',
            'wireguard_profile_id' => $wireguardProfileId,
        ]);

        return [$panel, $secret];
    }

    protected function runDeleteFlow(int $panelId): FakeNutgram
    {
        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panelId}")->reply();
        $bot->hearCallbackQueryData('42')->reply();
        $bot->hearCallbackQueryData('x@@@@@@@@@')->reply(); // 10th grid button = confirmDeleteServer
        $bot->hearCallbackQueryData('yes')->reply(); // doDeleteServer

        return $bot;
    }

    public function test_deleting_a_server_with_no_wireguard_profile_does_not_touch_pasarguard(): void
    {
        [$panel] = $this->deleteServerPanelAndSecret();

        Http::fake([
            'api.digitalocean.com/v2/droplets/42' => Http::response([], 204),
        ]);

        $bot = $this->runDeleteFlow($panel->id);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'api/admin/token'));
        $this->assertCount(0, array_filter($bot->getRequestHistory(), function ($item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            return isset($body['text']) && str_contains($body['text'], 'نود');
        }));
    }

    public function test_deleting_a_noded_server_also_deletes_its_pasarguard_node(): void
    {
        config([
            'pasarguard.panel.url' => 'https://panel.test',
            'pasarguard.panel.username' => 'admin',
            'pasarguard.panel.password' => 'secret',
        ]);

        $profile = WireguardProfile::create([
            'name' => 'server3',
            'private_key' => 'priv',
            'created_by' => 555,
            'core_id' => 99,
        ]);

        [$panel] = $this->deleteServerPanelAndSecret($profile->id);

        Http::fake([
            'api.digitalocean.com/v2/droplets/42' => Http::response([], 204),
            'panel.test/api/admin/token' => Http::response(['access_token' => 'tok']),
            'panel.test/api/node/99' => Http::response([], 200),
        ]);

        $bot = $this->runDeleteFlow($panel->id);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/api/node/99'));

        $this->assertNull($profile->fresh()->core_id);

        $lastText = null;
        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['text']) && str_contains($body['text'], 'نود')) {
                $lastText = $body['text'];
                break;
            }
        }

        $this->assertNotNull($lastText);
        $this->assertStringContainsString('حذف شد', $lastText);
    }

    public function test_deleting_a_noded_server_without_panel_configured_tells_the_admin_to_delete_manually(): void
    {
        // Explicitly empty rather than relying on the ambient default — a
        // real deploy's .env may have these genuinely configured.
        config([
            'pasarguard.panel.url' => null,
            'pasarguard.panel.username' => null,
            'pasarguard.panel.password' => null,
        ]);

        $profile = WireguardProfile::create([
            'name' => 'server3',
            'private_key' => 'priv',
            'created_by' => 555,
            'core_id' => 99,
        ]);

        [$panel] = $this->deleteServerPanelAndSecret($profile->id);

        Http::fake([
            'api.digitalocean.com/v2/droplets/42' => Http::response([], 204),
        ]);

        $bot = $this->runDeleteFlow($panel->id);

        // core_id is left untouched — we don't know it was actually cleaned up.
        $this->assertSame(99, $profile->fresh()->core_id);

        $lastText = null;
        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['text']) && str_contains($body['text'], 'نود')) {
                $lastText = $body['text'];
                break;
            }
        }

        $this->assertNotNull($lastText);
        $this->assertStringContainsString('تنظیم نشده', $lastText);
    }
}
