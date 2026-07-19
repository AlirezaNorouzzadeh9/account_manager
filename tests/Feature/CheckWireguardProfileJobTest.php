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

        $sibling = WireguardProfile::where('name', 'server1')->first();
        $this->assertSame($sibling->id, $down->fresh()->borrowed_from_id);
    }

    public function test_does_not_resync_dns_while_the_borrowed_sibling_is_still_healthy(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test', 'dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1']);

        $sibling = $this->makeProfile('server1', ownIp: '9.9.9.1'); // still healthy
        $down = $this->makeProfile('server3', alerted: true, ownIp: '1.2.3.3');
        $down->update(['borrowed_from_id' => $sibling->id]);

        $this->fakePing(ok: false); // server3's own IP is still down

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($down->id))->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'dns_records'));
        $this->assertSame($sibling->id, $down->fresh()->borrowed_from_id);
    }

    public function test_reconsiders_failover_when_the_borrowed_sibling_has_also_gone_down(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test', 'dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1']);

        // server3 is down and had earlier borrowed server2's IP — but
        // server2 has SINCE gone down too (ping_alerted=true), and a third
        // profile (server1) is the only one still actually healthy.
        $server2 = $this->makeProfile('server2', alerted: true, ownIp: '2.2.2.2');
        $server1 = $this->makeProfile('server1', ownIp: '9.9.9.1');
        $down = $this->makeProfile('server3', alerted: true, ownIp: '3.3.3.3');
        $down->update(['borrowed_from_id' => $server2->id]);

        $this->fakePing(ok: false); // server3's own IP is still down

        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::timeoutPings()]]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($down->id))->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        // Re-failed-over to the still-healthy server1, not the now-dead server2.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '9.9.9.1'
            && ($request->data()['name'] ?? null) === 'server3.node.test');

        $this->assertSame($server1->id, $down->fresh()->borrowed_from_id);
    }

    public function test_reconsiders_failover_when_no_sibling_was_ever_available(): void
    {
        config(['dns.cloudflare.node_domain' => 'node.test', 'dns.cloudflare.api_token' => 'cf-token', 'dns.cloudflare.zone_id' => 'zone-1']);

        // server3 went down while every sibling was ALSO down — no borrow
        // ever succeeded (borrowed_from_id stayed null). One has since
        // recovered and must now be picked up on a later run.
        $down = $this->makeProfile('server3', alerted: true, ownIp: '3.3.3.3');
        $recovered = $this->makeProfile('server1', ownIp: '9.9.9.1');

        $this->fakePing(ok: false);
        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::timeoutPings()]]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($down->id))->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'dns_records')
            && ($request->data()['content'] ?? null) === '9.9.9.1');

        $this->assertSame($recovered->id, $down->fresh()->borrowed_from_id);
    }

    public function test_recovery_clears_the_borrowed_from_id(): void
    {
        config([
            'dns.cloudflare.node_domain' => 'node.test',
            'dns.cloudflare.api_token' => 'cf-token',
            'dns.cloudflare.zone_id' => 'zone-1',
        ]);

        $sibling = $this->makeProfile('server1', ownIp: '9.9.9.1');
        $profile = $this->makeProfile('server3', alerted: true, ownIp: '5.5.5.5');
        $profile->update(['borrowed_from_id' => $sibling->id]);

        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'fake-request-id', 'nodes' => []]),
            'check-host.net/check-result/*' => Http::response(['ir5.node.check-host.net' => [self::okPings()]]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'rec-1']]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckWireguardProfileJob($profile->id))->handle(new CheckHostClient(), $this->fakeDns([]), $bot);

        $this->assertNull($profile->fresh()->borrowed_from_id);
    }
}
