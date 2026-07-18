<?php

namespace Tests\Feature;

use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use App\Services\Pasarguard\NodeFailoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class NodeFailoverServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makePanel(): Panel
    {
        return Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);
    }

    protected function fakeCloudflare(): void
    {
        config(['dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1', 'dns.cloudflare.node_domain' => 'node.test']);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);
    }

    public function test_does_nothing_when_the_down_server_has_no_wireguard_profile(): void
    {
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '1',
            'root_password' => 'pw',
            'hostname' => 'down-server',
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        $this->assertEmpty($bot->getRequestHistory());
        Http::assertNothingSent();
    }

    public function test_fails_over_dns_to_a_healthy_sibling(): void
    {
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $downProfile = WireguardProfile::create(['name' => 'server3', 'private_key' => 'priv-down', 'created_by' => 555]);
        $siblingProfile = WireguardProfile::create(['name' => 'server1', 'private_key' => 'priv-sibling', 'created_by' => 555]);

        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '3',
            'root_password' => 'pw',
            'hostname' => 'server3',
            'wireguard_profile_id' => $downProfile->id,
            'ping_alerted' => true,
        ]);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '1',
            'root_password' => 'pw',
            'hostname' => 'server1',
            'wireguard_profile_id' => $siblingProfile->id,
            'ping_alerted' => false,
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
            'api.digitalocean.com/v2/droplets/1' => Http::response([
                'droplet' => ['id' => 1, 'name' => 'server1', 'networks' => ['v4' => [['ip_address' => '9.9.9.1', 'type' => 'public']]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        // The down profile's domain ("server3.node.test") was pointed at
        // the healthy sibling's ("server1") live IP.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '9.9.9.1'
            && ($request->data()['name'] ?? null) === 'server3.node.test');

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/droplets/3'));

        $this->assertNotEmpty($bot->getRequestHistory());
    }

    public function test_does_nothing_when_no_healthy_sibling_exists(): void
    {
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $profile = WireguardProfile::create(['name' => 'server3', 'private_key' => 'priv-down', 'created_by' => 555]);

        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '3',
            'root_password' => 'pw',
            'hostname' => 'server3',
            'wireguard_profile_id' => $profile->id,
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'dns_records'));
        $this->assertEmpty($bot->getRequestHistory());
    }

    public function test_ignores_a_sibling_belonging_to_a_different_admin(): void
    {
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $otherPanel = Panel::create([
            'name' => 'Someone Else',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 999,
        ]);

        $downProfile = WireguardProfile::create(['name' => 'server3', 'private_key' => 'priv-down', 'created_by' => 555]);
        $otherProfile = WireguardProfile::create(['name' => 'other', 'private_key' => 'priv-other', 'created_by' => 999]);

        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '3',
            'root_password' => 'pw',
            'hostname' => 'server3',
            'wireguard_profile_id' => $downProfile->id,
        ]);

        ServerSecret::create([
            'panel_id' => $otherPanel->id,
            'provider_server_id' => '9',
            'root_password' => 'pw',
            'hostname' => 'other-server',
            'wireguard_profile_id' => $otherProfile->id,
            'ping_alerted' => false,
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        Http::assertNothingSent();
    }
}
