<?php

namespace Tests\Feature;

use App\Jobs\CheckWireguardProfileJob;
use App\Models\WireguardProfile;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class CheckWireguardProfileJobTest extends TestCase
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
                'ir5.node.check-host.net' => $ok ? [[['OK', 0.08, '9.9.9.9']]] : [[['TIMEOUT', 3.0]]],
            ]),
        ]);
    }

    protected function makeProfile(string $name, bool $alerted = false): WireguardProfile
    {
        return WireguardProfile::create([
            'name' => $name,
            'private_key' => "priv-{$name}",
            'created_by' => 555,
            'ping_alerted' => $alerted,
        ]);
    }

    public function test_a_failing_domain_alerts_the_owner_and_marks_the_profile_alerted(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test']);
        $profile = $this->makeProfile('server3');
        $this->fakePing(ok: false);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))
            ->handle(new CheckHostClient(), $this->fakeDns(['server3.node.test' => '1.2.3.3']), $bot);

        $this->assertNotEmpty($bot->getRequestHistory());
        $this->assertTrue($profile->fresh()->ping_alerted);
    }

    public function test_a_still_failing_domain_does_not_alert_again(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test']);
        $profile = $this->makeProfile('server3', alerted: true);
        $this->fakePing(ok: false);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))
            ->handle(new CheckHostClient(), $this->fakeDns(['server3.node.test' => '1.2.3.3']), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertTrue($profile->fresh()->ping_alerted);
    }

    public function test_a_clean_domain_ping_clears_a_previously_set_alert_flag(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test']);
        $profile = $this->makeProfile('server3', alerted: true);
        $this->fakePing(ok: true);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))
            ->handle(new CheckHostClient(), $this->fakeDns(['server3.node.test' => '1.2.3.3']), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertFalse($profile->fresh()->ping_alerted);
    }

    public function test_a_domain_that_does_not_resolve_is_skipped(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test']);
        $profile = $this->makeProfile('server3');

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))
            ->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        Http::assertNothingSent();
        $this->assertFalse($profile->fresh()->ping_alerted);
    }

    public function test_failover_repoints_the_down_domain_at_a_healthy_sibling(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test', 'dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1']);

        $down = $this->makeProfile('server3');
        $this->makeProfile('server1'); // healthy sibling, ping_alerted stays false

        $this->fakePing(ok: false);
        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]]]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($down->id))->handle(
            new CheckHostClient(),
            $this->fakeDns(['server3.node.test' => '1.2.3.3', 'server1.node.test' => '9.9.9.1']),
            $bot,
        );

        // The DOWN profile's own domain gets repointed at the sibling's IP.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '9.9.9.1'
            && ($request->data()['name'] ?? null) === 'server3.node.test');
    }

    public function test_failover_skips_a_sibling_that_is_also_alerted(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test', 'dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1']);

        $down = $this->makeProfile('server3');
        $this->makeProfile('server1', alerted: true); // also down — must not be borrowed from

        $this->fakePing(ok: false);
        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]]]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($down->id))->handle(
            new CheckHostClient(),
            $this->fakeDns(['server3.node.test' => '1.2.3.3', 'server1.node.test' => '9.9.9.1']),
            $bot,
        );

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'dns_records'));
    }
}
