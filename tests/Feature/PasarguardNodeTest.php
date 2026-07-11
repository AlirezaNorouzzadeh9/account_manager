<?php

namespace Tests\Feature;

use App\Jobs\InstallPasarguardNodeJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardLocation;
use App\Models\WireguardProfile;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        $bot->hearCallbackQueryData('x@@@@@@')->reply(); // confirmInstallNode -> wireguard profile picker
        $bot->hearCallbackQueryData('none')->reply(); // "بدون وایرگارد", no secret stored yet
        $bot->hearText('the-current-root-password')->reply();

        $this->assertDatabaseHas('server_secrets', [
            'panel_id' => $panel->id,
            'provider_server_id' => 56,
        ]);
        $this->assertSame('the-current-root-password', ServerSecret::first()->root_password);

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

    public function test_install_node_registers_a_new_panel_node_when_panel_is_configured(): void
    {
        config([
            'pasarguard.panel.url' => 'https://panel.test',
            'pasarguard.panel.username' => 'bots',
            'pasarguard.panel.password' => 'secret',
            'pasarguard.api_key' => 'fixed-api-key',
        ]);

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-private-key']);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 61,
            'root_password' => 'already-known-password',
            'wireguard_profile_id' => $profile->id,
        ]);

        $droplet = [
            'id' => 61,
            'name' => 'srv-4',
            'networks' => ['v4' => [['ip_address' => '8.8.8.8', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets/61' => Http::response(['droplet' => $droplet]),
            'panel.test/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer']),
            'panel.test/api/node' => Http::response(['id' => 777]),
        ]);

        $installer = \Mockery::mock(PasarguardNodeInstaller::class);
        $installer->shouldReceive('install')
            ->once()
            // No profile name passed — the manual install always uses a
            // per-IP cert/registration now, never the DNS-backed domain one.
            ->with('8.8.8.8', 'root', 'already-known-password', 'fake-private-key')
            ->andReturn(['success' => true, 'message' => 'نود پاسارگارد با موفقیت نصب و اجرا شد.', 'log' => '', 'cert' => 'fake-cert-pem', 'domain' => null, 'dns_warning' => null]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new InstallPasarguardNodeJob($panel->id, 61, $bot->chatId() ?? 1);
        $job->handle($bot, $installer);

        $this->assertSame(777, $profile->fresh()->core_id);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/api/node')
            && $request->method() === 'POST'
            && ($request['address'] ?? null) === '8.8.8.8'
            && ($request['server_ca'] ?? null) === 'fake-cert-pem'
            && ($request['api_key'] ?? null) === 'fixed-api-key');

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('نود جدید (id=777) در پنل PasarGuard ثبت شد', $body['text']);
    }

    public function test_install_node_falls_back_to_a_manual_message_when_panel_is_not_configured(): void
    {
        config([
            'pasarguard.panel.url' => null,
            'pasarguard.panel.username' => null,
            'pasarguard.panel.password' => null,
        ]);

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-private-key']);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 62,
            'root_password' => 'already-known-password',
            'wireguard_profile_id' => $profile->id,
        ]);

        $droplet = [
            'id' => 62,
            'name' => 'srv-5',
            'networks' => ['v4' => [['ip_address' => '8.8.4.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets/62' => Http::response(['droplet' => $droplet]),
        ]);

        $installer = \Mockery::mock(PasarguardNodeInstaller::class);
        $installer->shouldReceive('install')
            ->once()
            ->andReturn(['success' => true, 'message' => 'نود پاسارگارد با موفقیت نصب و اجرا شد.', 'log' => '', 'cert' => 'fake-cert-pem', 'domain' => null, 'dns_warning' => null]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new InstallPasarguardNodeJob($panel->id, 62, $bot->chatId() ?? 1);
        $job->handle($bot, $installer);

        $this->assertNull($profile->fresh()->core_id);

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('اطلاعات پنل PasarGuard تنظیم نشده', $body['text']);
        $this->assertStringContainsString('fake-cert-pem', $body['text']);
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
        $this->assertStringContainsString('network_mode: host', $compose);
        $this->assertStringNotContainsString('ports:', $compose);
    }

    public function test_location_config_uses_fixed_defaults_and_the_given_private_key(): void
    {
        $location = new WireguardLocation([
            'name' => 'germany',
            'ip' => '212.102.54.131',
            'server_public_key' => 'vIMHzH5FHdVkrhOOc0u/FySVhumaLC3XUk39Wk34LnE=',
        ]);

        $installer = new PasarguardNodeInstaller();
        $ref = new \ReflectionClass($installer);
        $method = $ref->getMethod('buildLocationConfig');
        $method->setAccessible(true);

        $config = $method->invoke($installer, $location, 'fake-private-key');

        $this->assertStringContainsString('Address = 10.14.0.2/16', $config);
        $this->assertStringContainsString('PrivateKey = fake-private-key', $config);
        $this->assertStringContainsString('DNS = 162.252.172.57, 149.154.159.92', $config);
        $this->assertStringContainsString('Table = off', $config);
        $this->assertStringContainsString('PublicKey = vIMHzH5FHdVkrhOOc0u/FySVhumaLC3XUk39Wk34LnE=', $config);
        $this->assertStringContainsString('AllowedIPs = 0.0.0.0/0', $config);
        $this->assertStringContainsString('Endpoint = 212.102.54.131:51820', $config);
    }

    public function test_dns_configured_requires_both_zone_id_and_api_token(): void
    {
        $installer = new PasarguardNodeInstaller();
        $ref = new \ReflectionClass($installer);
        $method = $ref->getMethod('dnsConfigured');
        $method->setAccessible(true);

        config(['dns.cloudflare.zone_id' => null, 'dns.cloudflare.api_token' => null]);
        $this->assertFalse($method->invoke($installer));

        config(['dns.cloudflare.zone_id' => 'zone-1', 'dns.cloudflare.api_token' => null]);
        $this->assertFalse($method->invoke($installer));

        config(['dns.cloudflare.zone_id' => 'zone-1', 'dns.cloudflare.api_token' => 'token-1']);
        $this->assertTrue($method->invoke($installer));
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

        WireguardLocation::create([
            'name' => 'it',
            'ip' => '1.2.3.4',
            'server_public_key' => 'fake-pub',
        ]);

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
        $bot->hearCallbackQueryData('x@@@@@@@')->reply(); // 8th x-prefixed button = updateWireguards -> profile picker
        $bot->hearCallbackQueryData('none')->reply(); // wireguard profile picker: "بدون وایرگارد"

        Queue::assertPushed(UpdateWireguardsJob::class);
    }

    public function test_sync_profile_dns_returns_null_when_cloudflare_is_not_configured(): void
    {
        config(['dns.cloudflare.api_token' => null, 'dns.cloudflare.zone_id' => null]);

        $result = (new PasarguardNodeInstaller())->syncProfileDns('germany', '9.9.9.9');

        $this->assertNull($result);
    }

    public function test_sync_profile_dns_upserts_the_a_record_for_the_profiles_domain(): void
    {
        config([
            'dns.cloudflare.api_token' => 'token-1',
            'dns.cloudflare.zone_id' => 'zone-1',
            'dns.cloudflare.node_domain' => 'node.pcbot.top',
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        $result = (new PasarguardNodeInstaller())->syncProfileDns('germany', '9.9.9.9');

        $this->assertSame('germany.node.pcbot.top', $result['domain']);
        $this->assertNull($result['error']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ($request['name'] ?? null) === 'germany.node.pcbot.top'
            && ($request['content'] ?? null) === '9.9.9.9');
    }

    public function test_sync_profile_dns_returns_the_error_on_failure(): void
    {
        config([
            'dns.cloudflare.api_token' => 'token-1',
            'dns.cloudflare.zone_id' => 'zone-1',
            'dns.cloudflare.node_domain' => 'node.pcbot.top',
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid zone']],
            ], 400),
        ]);

        $result = (new PasarguardNodeInstaller())->syncProfileDns('germany', '9.9.9.9');

        $this->assertSame('germany.node.pcbot.top', $result['domain']);
        $this->assertStringContainsString('Invalid zone', $result['error']);
    }
}
