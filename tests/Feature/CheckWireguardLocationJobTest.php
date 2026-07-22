<?php

namespace Tests\Feature;

use App\Jobs\CheckWireguardLocationJob;
use App\Jobs\ConnectServerWireguardsJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\ConnectedServer;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardLocation;
use App\Models\WireguardProfile;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Dns\DnsResolver;
use App\Services\Wireguard\LocationHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class CheckWireguardLocationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeDns(array $map): DnsResolver
    {
        return new class($map) extends DnsResolver
        {
            public function __construct(private array $map)
            {
            }

            public function resolve(string $domain): ?string
            {
                return $this->map[$domain] ?? null;
            }
        };
    }

    protected function fakePing(bool $ok): void
    {
        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => $ok ? [self::okPings()] : [self::timeoutPings()],
            ]),
        ]);
    }

    protected function makeLocation(array $overrides = []): WireguardLocation
    {
        return WireguardLocation::create(array_merge([
            'name' => 'germany',
            'ip' => '1.2.3.4',
            'hostname' => 'de.example.com',
            'server_public_key' => 'pub',
            'created_by' => 555,
            'ping_alerted' => false,
        ], $overrides));
    }

    public function test_a_location_without_a_hostname_is_skipped(): void
    {
        $location = $this->makeLocation(['hostname' => null]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardLocationJob($location->id))->handle(new CheckHostClient(), new LocationHealer($this->fakeDns([])), $bot);

        Http::assertNothingSent();
        $this->assertEmpty($bot->getRequestHistory());
    }

    public function test_a_healthy_ip_does_nothing_when_not_previously_alerted(): void
    {
        $location = $this->makeLocation();
        $this->fakePing(ok: true);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardLocationJob($location->id))->handle(new CheckHostClient(), new LocationHealer($this->fakeDns([])), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertFalse($location->fresh()->ping_alerted);
    }

    public function test_a_recovered_ip_clears_the_alert_and_notifies(): void
    {
        $location = $this->makeLocation(['ping_alerted' => true]);
        $this->fakePing(ok: true);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardLocationJob($location->id))->handle(new CheckHostClient(), new LocationHealer($this->fakeDns([])), $bot);

        $this->assertFalse($location->fresh()->ping_alerted);
        $this->assertNotEmpty($bot->getRequestHistory());
    }

    public function test_an_unhealthy_ip_heals_by_reresolving_and_pushes_updates_to_panel_and_connected_servers(): void
    {
        Queue::fake();

        $location = $this->makeLocation();
        $this->fakePing(ok: false);

        $panel = Panel::create([
            'name' => 'My Panel', 'provider' => 'digitalocean', 'api_token' => 'tok',
            'meta' => [], 'is_active' => true, 'created_by' => 555,
        ]);
        $profile = WireguardProfile::create(['name' => 'server-main', 'private_key' => 'priv', 'created_by' => 555]);
        ServerSecret::create([
            'panel_id' => $panel->id, 'provider_server_id' => 99,
            'root_password' => 'pw', 'wireguard_profile_id' => $profile->id,
        ]);
        ConnectedServer::create([
            'host' => '9.9.9.9', 'username' => 'root', 'password' => 'pw',
            'wireguard_profile_id' => $profile->id, 'created_by' => 555,
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardLocationJob($location->id))
            ->handle(new CheckHostClient(), new LocationHealer($this->fakeDns(['de.example.com' => '5.6.7.8'])), $bot);

        $this->assertSame('5.6.7.8', $location->fresh()->ip);
        $this->assertFalse($location->fresh()->ping_alerted);
        $this->assertNotEmpty($bot->getRequestHistory());

        Queue::assertPushed(UpdateWireguardsJob::class, function ($job) use ($panel) {
            return $this->privateProp($job, 'panelId') === $panel->id
                // provider_server_id is a string DB column — the value
                // queried back out is "99", not the int 99 passed to create().
                && $this->privateProp($job, 'serverId') === '99';
        });

        Queue::assertPushed(ConnectServerWireguardsJob::class, function ($job) {
            return $this->privateProp($job, 'host') === '9.9.9.9'
                && $this->privateProp($job, 'wireguardPrivateKey') === 'priv';
        });
    }

    public function test_an_unhealthy_ip_that_resolves_to_the_same_ip_just_alerts_without_pushing_updates(): void
    {
        Queue::fake();

        $location = $this->makeLocation();
        $this->fakePing(ok: false);

        (new CheckWireguardLocationJob($location->id))
            ->handle(new CheckHostClient(), new LocationHealer($this->fakeDns(['de.example.com' => $location->ip])), $this->app->make(Nutgram::class));

        $this->assertSame('1.2.3.4', $location->fresh()->ip);
        $this->assertTrue($location->fresh()->ping_alerted);
        Queue::assertNotPushed(UpdateWireguardsJob::class);
    }

    public function test_an_unhealthy_ip_that_fails_to_resolve_just_alerts(): void
    {
        Queue::fake();

        $location = $this->makeLocation();
        $this->fakePing(ok: false);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardLocationJob($location->id))->handle(new CheckHostClient(), new LocationHealer($this->fakeDns([])), $bot);

        $this->assertSame('1.2.3.4', $location->fresh()->ip);
        $this->assertTrue($location->fresh()->ping_alerted);
        $this->assertNotEmpty($bot->getRequestHistory());
        Queue::assertNotPushed(UpdateWireguardsJob::class);
    }

    public function test_a_still_unhealthy_ip_does_not_alert_again(): void
    {
        $location = $this->makeLocation(['ping_alerted' => true]);
        $this->fakePing(ok: false);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardLocationJob($location->id))->handle(new CheckHostClient(), new LocationHealer($this->fakeDns([])), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertTrue($location->fresh()->ping_alerted);
    }

    protected function privateProp(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
