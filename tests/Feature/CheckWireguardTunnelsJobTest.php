<?php

namespace Tests\Feature;

use App\Jobs\CheckWireguardTunnelsJob;
use App\Jobs\ConnectServerWireguardsJob;
use App\Jobs\UpdateWireguardsJob;
use App\Models\ConnectedServer;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardLocation;
use App\Models\WireguardProfile;
use App\Services\Dns\DnsResolver;
use App\Services\Wireguard\LocationHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class CheckWireguardTunnelsJobTest extends TestCase
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

    public function test_a_working_tunnel_does_nothing_when_not_previously_alerted(): void
    {
        $this->makeLocation();
        Process::fake(['*' => Process::result(output: "germany|5.6.7.8\n")]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardTunnelsJob())->handle(new LocationHealer($this->fakeDns([])), $bot);

        $this->assertEmpty($bot->getRequestHistory());
    }

    public function test_a_recovered_tunnel_clears_the_alert_and_notifies(): void
    {
        $location = $this->makeLocation(['ping_alerted' => true]);
        Process::fake(['*' => Process::result(output: "germany|5.6.7.8\n")]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardTunnelsJob())->handle(new LocationHealer($this->fakeDns([])), $bot);

        $this->assertFalse($location->fresh()->ping_alerted);
        $this->assertNotEmpty($bot->getRequestHistory());
    }

    public function test_a_failed_tunnel_heals_by_reresolving_and_pushes_updates(): void
    {
        Queue::fake();

        $location = $this->makeLocation();
        Process::fake(['*' => Process::result(output: "germany|FAILED\n")]);

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

        (new CheckWireguardTunnelsJob())
            ->handle(new LocationHealer($this->fakeDns(['de.example.com' => '5.6.7.8'])), $bot);

        $this->assertSame('5.6.7.8', $location->fresh()->ip);
        $this->assertFalse($location->fresh()->ping_alerted);
        $this->assertNotEmpty($bot->getRequestHistory());

        Queue::assertPushed(UpdateWireguardsJob::class);
        Queue::assertPushed(ConnectServerWireguardsJob::class);
    }

    public function test_a_failed_tunnel_that_cannot_be_healed_alerts_once(): void
    {
        Queue::fake();

        $location = $this->makeLocation();
        Process::fake(['*' => Process::result(output: "germany|FAILED\n")]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardTunnelsJob())->handle(new LocationHealer($this->fakeDns([])), $bot);

        $this->assertSame('1.2.3.4', $location->fresh()->ip);
        $this->assertTrue($location->fresh()->ping_alerted);
        $this->assertNotEmpty($bot->getRequestHistory());
        Queue::assertNotPushed(UpdateWireguardsJob::class);
    }

    public function test_a_still_failed_tunnel_does_not_alert_again(): void
    {
        $location = $this->makeLocation(['ping_alerted' => true]);
        Process::fake(['*' => Process::result(output: "germany|FAILED\n")]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardTunnelsJob())->handle(new LocationHealer($this->fakeDns([])), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertTrue($location->fresh()->ping_alerted);
    }

    public function test_an_interface_with_no_matching_location_is_ignored(): void
    {
        Process::fake(['*' => Process::result(output: "unknown-iface|1.2.3.4\n")]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardTunnelsJob())->handle(new LocationHealer($this->fakeDns([])), $bot);

        $this->assertEmpty($bot->getRequestHistory());
    }

    public function test_multiple_interfaces_in_one_run_are_each_evaluated(): void
    {
        $healthy = $this->makeLocation(['name' => 'germany', 'ip' => '1.2.3.4', 'ping_alerted' => true]);
        $stillDown = $this->makeLocation(['name' => 'france', 'ip' => '9.9.9.9', 'hostname' => 'fr.example.com', 'ping_alerted' => true]);

        Process::fake(['*' => Process::result(output: "germany|5.6.7.8\nfrance|FAILED\n")]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardTunnelsJob())->handle(new LocationHealer($this->fakeDns([])), $bot);

        $this->assertFalse($healthy->fresh()->ping_alerted);
        $this->assertTrue($stillDown->fresh()->ping_alerted);
    }
}
