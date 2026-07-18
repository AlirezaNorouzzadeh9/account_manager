<?php

namespace Tests\Feature;

use App\Jobs\ReplaceServerPollJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use App\Services\Pasarguard\NodeFailoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
        Queue::fake();
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '1',
            'root_password' => 'pw',
            'region' => 'nyc1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64', 'hostname' => 'down-server',
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        $this->assertEmpty($bot->getRequestHistory());
        Queue::assertNothingPushed();
    }

    public function test_fails_over_dns_to_a_healthy_sibling_and_rebuilds_the_down_server(): void
    {
        Queue::fake();
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $downProfile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'priv-down', 'created_by' => 555]);
        $siblingProfile = WireguardProfile::create(['name' => 'france', 'private_key' => 'priv-sibling', 'created_by' => 555]);

        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '1',
            'root_password' => 'pw',
            'region' => 'nyc1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64', 'hostname' => 'down-server',
            'wireguard_profile_id' => $downProfile->id,
            'ping_alerted' => true,
        ]);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '2',
            'root_password' => 'pw',
            'region' => 'nyc1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64', 'hostname' => 'healthy-server',
            'wireguard_profile_id' => $siblingProfile->id,
            'ping_alerted' => false,
        ]);

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
            'api.digitalocean.com/v2/droplets/2' => Http::response([
                'droplet' => ['id' => 2, 'name' => 'healthy-server', 'networks' => ['v4' => [['ip_address' => '9.9.9.2', 'type' => 'public']]]],
            ]),
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 3, 'name' => 'down-server'],
                'links' => ['actions' => [['id' => 777, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        // DNS was pointed at the healthy sibling's live IP.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '9.9.9.2'
            && ($request->data()['name'] ?? null) === 'germany.node.test');

        $this->assertNotEmpty($bot->getRequestHistory());

        // A real replacement build was kicked off for the down server.
        Queue::assertPushed(ReplaceServerPollJob::class, function (ReplaceServerPollJob $job) {
            $ref = new \ReflectionProperty($job, 'oldServerId');
            $ref->setAccessible(true);

            return $ref->getValue($job) === '1';
        });
    }

    public function test_skips_dns_failover_but_still_rebuilds_when_no_healthy_sibling_exists(): void
    {
        Queue::fake();
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'priv-down', 'created_by' => 555]);

        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '1',
            'root_password' => 'pw',
            'region' => 'nyc1', 'size' => 's-1vcpu-1gb', 'image' => 'ubuntu-24-04-x64', 'hostname' => 'down-server',
            'wireguard_profile_id' => $profile->id,
        ]);

        Http::fake([
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 3, 'name' => 'down-server'],
                'links' => ['actions' => [['id' => 777, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'dns_records'));
        Queue::assertPushed(ReplaceServerPollJob::class);
    }

    public function test_does_not_rebuild_when_the_down_server_has_no_saved_build_spec(): void
    {
        Queue::fake();
        $this->fakeCloudflare();

        $panel = $this->makePanel();
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'priv-down', 'created_by' => 555]);

        $down = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '1',
            'root_password' => 'pw',
            'wireguard_profile_id' => $profile->id,
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        app(NodeFailoverService::class)->handle($down, $bot, 555);

        Queue::assertNothingPushed();
    }
}
