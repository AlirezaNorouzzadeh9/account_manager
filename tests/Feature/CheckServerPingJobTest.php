<?php

namespace Tests\Feature;

use App\Jobs\CheckServerPingJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\CheckHost\CheckHostClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class CheckServerPingJobTest extends TestCase
{
    use RefreshDatabase;

    protected function fakePing(bool $ok): void
    {
        Http::fake([
            'check-host.net/check-ping*' => Http::response([
                'ok' => 1,
                'request_id' => 'fake-request-id',
                'nodes' => [],
            ]),
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => $ok ? [[['OK', 0.08, '9.9.9.9']]] : [[['TIMEOUT', 3.0]]],
            ]),
        ]);
    }

    public function test_a_failing_ping_alerts_the_owner_and_marks_the_server_as_alerted(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $secret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '42',
            'root_password' => 'pw',
        ]);

        $this->fakePing(ok: false);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckServerPingJob($panel->id, '42', '9.9.9.9', 'my-server-1'))
            ->handle(new CheckHostClient(), $bot);

        $this->assertCount(1, $bot->getRequestHistory());
        $this->assertTrue($secret->fresh()->ping_alerted);
    }

    public function test_a_still_failing_ping_does_not_alert_again(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $secret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '42',
            'root_password' => 'pw',
            'ping_alerted' => true,
        ]);

        $this->fakePing(ok: false);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckServerPingJob($panel->id, '42', '9.9.9.9', 'my-server-1'))
            ->handle(new CheckHostClient(), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertTrue($secret->fresh()->ping_alerted);
    }

    public function test_a_clean_ping_clears_a_previously_set_alert_flag(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $secret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => '42',
            'root_password' => 'pw',
            'ping_alerted' => true,
        ]);

        $this->fakePing(ok: true);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        (new CheckServerPingJob($panel->id, '42', '9.9.9.9', 'my-server-1'))
            ->handle(new CheckHostClient(), $bot);

        $this->assertEmpty($bot->getRequestHistory());
        $this->assertFalse($secret->fresh()->ping_alerted);
    }
}
