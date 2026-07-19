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
                'ir5.node.check-host.net' => $ok ? [self::okPings()] : [self::timeoutPings()],
            ]),
        ]);
    }

    protected function makeProfile(string $name, bool $alerted = false, ?string $ownIp = null): WireguardProfile
    {
        return WireguardProfile::create([
            'name' => $name,
            'private_key' => "priv-{$name}",
            'created_by' => 555,
            'ping_alerted' => $alerted,
            'own_ip' => $ownIp,
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
        // Explicitly empty rather than relying on the ambient default — an
        // earlier test in the same run may have left Cloudflare configured.
        config([
            'dns.cloudflare.node_domain' => 'node.test',
            'dns.cloudflare.api_token' => null,
            'dns.cloudflare.zone_id' => null,
        ]);
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
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::timeoutPings()]]),
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
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::timeoutPings()]]),
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

    public function test_pings_the_profiles_own_ip_not_whatever_the_domain_currently_resolves_to(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test']);
        // Simulates a profile already failed over: the domain resolves to a
        // borrowed sibling IP, but its OWN server (own_ip) must still be the
        // one actually checked — otherwise it would never be seen recovering.
        $profile = $this->makeProfile('server3', alerted: true, ownIp: '5.5.5.5');
        $this->fakePing(ok: true);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))
            ->handle(new CheckHostClient(), $this->fakeDns(['server3.node.test' => '9.9.9.1']), $bot);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'check-ping')
            && str_contains((string) $request->url(), 'host=5.5.5.5'));
    }

    public function test_recovery_restores_the_domain_to_its_own_ip_and_notifies_the_admin(): void
    {
        config([
            'dns.cloudflare.node_domain' => 'node.test',
            'dns.cloudflare.api_token' => 'cf-token',
            'dns.cloudflare.zone_id' => 'zone-1',
        ]);

        $profile = $this->makeProfile('server3', alerted: true, ownIp: '5.5.5.5');

        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::okPings()]]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))
            ->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        $this->assertFalse($profile->fresh()->ping_alerted);

        // The domain was repointed back to the profile's own IP.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '5.5.5.5'
            && ($request->data()['name'] ?? null) === 'server3.node.test');

        $lastText = null;
        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['text'])) {
                $lastText = $body['text'];
                break;
            }
        }

        $this->assertNotNull($lastText);
        $this->assertStringContainsString('برگشت', $lastText);
    }

    public function test_failover_uses_the_siblings_own_ip_when_set(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test', 'dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1']);

        $down = $this->makeProfile('server3', ownIp: '1.2.3.3');
        $this->makeProfile('server1', ownIp: '9.9.9.1'); // healthy sibling, resolved without any DNS lookup

        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::timeoutPings()]]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        // No DNS map at all — the sibling's own_ip must be used directly,
        // never falling through to a (here-unresolvable) domain lookup.
        (new CheckWireguardProfileJob($down->id))->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '9.9.9.1'
            && ($request->data()['name'] ?? null) === 'server3.node.test');
    }
}
