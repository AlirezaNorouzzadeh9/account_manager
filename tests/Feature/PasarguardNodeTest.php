<?php

namespace Tests\Feature;

use App\Jobs\InstallPasarguardNodeJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardConfig;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class PasarguardNodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_node_button_dispatches_job(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
        ]);

        $droplet = [
            'id' => 55,
            'name' => 'srv-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '5.5.5.5', 'type' => 'public']]],
        ];

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 55,
            'root_password' => 'already-known-password',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'api.digitalocean.com/v2/droplets?*' => \Illuminate\Support\Facades\Http::response(['droplets' => [$droplet], 'links' => [], 'meta' => ['total' => 1]]),
            'api.digitalocean.com/v2/droplets/55' => \Illuminate\Support\Facades\Http::response(['droplet' => $droplet]),
            'api.digitalocean.com/v2/reserved_ips*' => \Illuminate\Support\Facades\Http::response(['reserved_ips' => []]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('55')->reply();
        $bot->hearCallbackQueryData('x@@@@@@')->reply(); // 7th x-prefixed button in the grid = confirmInstallNode
        $bot->hearCallbackQueryData('none')->reply(); // wireguard profile picker: "بدون وایرگارد"
        $bot->hearCallbackQueryData('yes')->reply();

        Queue::assertPushed(InstallPasarguardNodeJob::class);
    }

    public function test_missing_secret_prompts_for_password_then_dispatches_job(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
        ]);

        $droplet = [
            'id' => 56,
            'name' => 'srv-2',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '6.6.6.6', 'type' => 'public']]],
        ];

        \Illuminate\Support\Facades\Http::fake([
            'api.digitalocean.com/v2/droplets?*' => \Illuminate\Support\Facades\Http::response(['droplets' => [$droplet], 'links' => [], 'meta' => ['total' => 1]]),
            'api.digitalocean.com/v2/droplets/56' => \Illuminate\Support\Facades\Http::response(['droplet' => $droplet]),
            'api.digitalocean.com/v2/reserved_ips*' => \Illuminate\Support\Facades\Http::response(['reserved_ips' => []]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('56')->reply();
        $bot->hearCallbackQueryData('x@@@@@@')->reply(); // confirmInstallNode, no secret stored yet
        $bot->hearCallbackQueryData('none')->reply(); // wireguard profile picker: "بدون وایرگارد"
        $bot->hearText('the-current-root-password')->reply();

        $this->assertDatabaseHas('server_secrets', [
            'panel_id' => $panel->id,
            'provider_server_id' => 56,
        ]);
        $this->assertSame('the-current-root-password', ServerSecret::first()->root_password);
        $this->assertNull(ServerSecret::first()->wireguard_profile_id);

        Queue::assertPushed(InstallPasarguardNodeJob::class);
    }

    public function test_job_reports_missing_secret_without_touching_ssh(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new InstallPasarguardNodeJob($panel->id, 999, $bot->chatId() ?? 1);
        $job->handle($bot, new PasarguardNodeInstaller());

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('رمز روت این سرور ذخیره نشده', $body['text']);
    }

    public function test_installer_env_file_and_compose_content(): void
    {
        $installer = new PasarguardNodeInstaller();
        $ref = new \ReflectionClass($installer);

        $envMethod = $ref->getMethod('buildEnvFile');
        $envMethod->setAccessible(true);

        $env = $envMethod->invoke($installer, false);
        $this->assertStringContainsString('SERVICE_PORT = 62050', $env);
        $this->assertStringContainsString('SSL_CERT_FILE = /var/lib/pg-node-1/certs/ssl_cert.pem', $env);
        $this->assertStringContainsString('SSL_KEY_FILE = /var/lib/pg-node-1/certs/ssl_key.pem', $env);
        $this->assertStringContainsString('API_KEY = '.config('pasarguard.api_key'), $env);
        $this->assertStringNotContainsString('PG_NODE_WG_HOST_ROUTING', $env);

        $envWithWg = $envMethod->invoke($installer, true);
        $this->assertStringContainsString('PG_NODE_WG_HOST_ROUTING = 1', $envWithWg);

        $compose = $ref->getConstant('COMPOSE_YAML');
        $this->assertStringContainsString('image: pasarguard/node:latest', $compose);
        $this->assertStringContainsString('env_file: node-1/.env', $compose);
        $this->assertStringContainsString('"8743:62050"', $compose);
    }

    public function test_interface_name_uses_config_name_and_dedupes_collisions(): void
    {
        $installer = new PasarguardNodeInstaller();
        $ref = new \ReflectionClass($installer);
        $method = $ref->getMethod('sanitizeInterfaceName');
        $method->setAccessible(true);

        $used = [];
        $this->assertSame('it', $method->invokeArgs($installer, ['it', &$used]));
        $this->assertSame(['it'], $used);

        // Special characters are stripped, not rejected.
        $this->assertSame('eu1', $method->invokeArgs($installer, ['eu-1!', &$used]));

        // A name colliding with an already-used one gets a numeric suffix.
        $used2 = ['it'];
        $this->assertSame('it1', $method->invokeArgs($installer, ['it', &$used2]));
    }

    public function test_update_wireguards_button_dispatches_job(): void
    {
        Queue::fake();

        WireguardConfig::create(['name' => 'it', 'config' => "[Interface]\n[Peer]"]);

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
        ]);

        $droplet = [
            'id' => 57,
            'name' => 'srv-3',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '7.7.7.7', 'type' => 'public']]],
        ];

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 57,
            'root_password' => 'already-known-password',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'api.digitalocean.com/v2/droplets?*' => \Illuminate\Support\Facades\Http::response(['droplets' => [$droplet], 'links' => [], 'meta' => ['total' => 1]]),
            'api.digitalocean.com/v2/droplets/57' => \Illuminate\Support\Facades\Http::response(['droplet' => $droplet]),
            'api.digitalocean.com/v2/reserved_ips*' => \Illuminate\Support\Facades\Http::response(['reserved_ips' => []]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('57')->reply();
        $bot->hearCallbackQueryData('x@@@@@@@')->reply(); // 8th x-prefixed button = updateWireguards
        $bot->hearCallbackQueryData('none')->reply(); // wireguard profile picker: "بدون وایرگارد"

        Queue::assertPushed(UpdateWireguardsJob::class);
    }
}
