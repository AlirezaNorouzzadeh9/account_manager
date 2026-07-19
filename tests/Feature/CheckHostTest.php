<?php

namespace Tests\Feature;

use App\Jobs\CreateServerFinalReportJob;
use App\Jobs\CreateServerReadyJob;
use App\Jobs\ServerPingCheckJob;
use App\Models\Panel;
use App\Services\CheckHost\CheckHostClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class CheckHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_host_client_formats_ping_results(): void
    {
        $client = new CheckHostClient();

        $result = [
            'ir5.node.check-host.net' => [[
                ['OK', 0.091, '1.2.3.4'],
                ['OK', 0.093],
            ]],
            'ir9.node.check-host.net' => [[
                ['TIMEOUT', 3.0],
            ]],
            'ir6.node.check-host.net' => [[
                ['OK', 0.112],
            ]],
            // No samples at all (not just failed ones) — must not render as "0/0".
            'ir7.node.check-host.net' => [[]],
        ];

        $formatted = $client->formatResult($result);

        $this->assertStringContainsString('Iran, Tehran 2/2 - 92ms', $formatted); // avg of 91/93 -> 92
        $this->assertStringContainsString('Iran, Khonj 0/1 - no response', $formatted);
        $this->assertStringContainsString('Iran, Qom', $formatted);
        $this->assertStringContainsString('Iran, Tehran - no response', $formatted); // ir7: 0 samples, no ratio shown
        $this->assertStringNotContainsString('0/0', $formatted);
    }

    public function test_all_nodes_ok_ignores_no_response_nodes_but_not_real_failures(): void
    {
        $client = new CheckHostClient();

        // A node with zero samples ("no response") is check-host's own probe
        // never reaching a verdict, not evidence our server is down — it
        // must not sink an otherwise-clean result.
        $this->assertTrue($client->allNodesOk([
            'ir5.node.check-host.net' => [[['OK', 0.09]]],
            'ir6.node.check-host.net' => [[['OK', 0.11]]],
            'ir7.node.check-host.net' => [[]],
        ]));

        // A single real failure among many otherwise-clean nodes (e.g. one
        // check-host probe having a bad measurement window) is tolerated —
        // this used to false-positive as "down" off one flaky node out of 8.
        $this->assertTrue($client->allNodesOk([
            'ir2.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            'ir3.node.check-host.net' => [[['OK', 0.08]]],
            'ir4.node.check-host.net' => [[['OK', 0.09]]],
            'ir5.node.check-host.net' => [[['OK', 0.07]]],
            'ir6.node.check-host.net' => [[['OK', 0.07]]],
            'ir7.node.check-host.net' => [[['OK', 0.08]]],
            'ir8.node.check-host.net' => [[['OK', 0.08]]],
            'ir9.node.check-host.net' => [[['OK', 0.09]]],
        ]));

        // Two or more real failures is a genuine problem, not noise.
        $this->assertFalse($client->allNodesOk([
            'ir5.node.check-host.net' => [[['OK', 0.09]]],
            'ir8.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            'ir9.node.check-host.net' => [[['TIMEOUT', 3.0]]],
        ]));

        // Tolerance only kicks in with 2+ evaluated nodes — a single
        // evaluated node that fails is a 100% failure rate, not noise.
        $this->assertFalse($client->allNodesOk([
            'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
        ]));

        // Every node came back with zero samples — inconclusive, not "clean".
        $this->assertFalse($client->allNodesOk([
            'ir5.node.check-host.net' => [[]],
            'ir6.node.check-host.net' => [[]],
        ]));

        $this->assertFalse($client->allNodesOk([]));
    }

    public function test_check_host_client_request_ping_returns_request_id(): void
    {
        Http::fake([
            'check-host.net/check-ping*' => Http::response([
                'ok' => 1,
                'request_id' => 'abc123',
                'permanent_link' => 'https://check-host.net/check-report/abc123',
                'nodes' => [],
            ]),
        ]);

        $client = new CheckHostClient();
        $requestId = $client->requestPing('1.2.3.4');

        $this->assertSame('abc123', $requestId);

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), 'check-ping')
                && str_contains((string) $request->url(), 'host=1.2.3.4')
                && substr_count((string) $request->url(), 'node=ir') === 8;
        });
    }

    public function test_create_server_ready_job_dispatches_final_report_job_without_sending_directly(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        Http::fake([
            'api.digitalocean.com/v2/actions/*' => Http::response([
                'action' => ['id' => 42, 'status' => 'completed', 'type' => 'create', 'resource_type' => 'droplet', 'resource_id' => 99],
            ]),
            'api.digitalocean.com/v2/droplets/99' => Http::response([
                'droplet' => [
                    'id' => 99,
                    'name' => 'my-server-1',
                    'networks' => ['v4' => [['ip_address' => '9.9.9.9', 'type' => 'public']]],
                ],
            ]),
            'check-host.net/check-ping*' => Http::response([
                'ok' => 1,
                'request_id' => 'fake-request-id',
                'nodes' => [],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerReadyJob($panel->id, 42, $bot->chatId() ?? 1, 'my-server-1', "user: root\npass: xyz");
        $job->handle($bot, new CheckHostClient());

        // The "server ready" message must NOT be sent yet — it's folded into
        // the combined message sent later by CreateServerFinalReportJob.
        $this->assertEmpty($bot->getRequestHistory());

        Queue::assertPushed(CreateServerFinalReportJob::class);
    }

    public function test_create_server_final_report_job_sends_one_combined_message(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['OK', 0.08, '9.9.9.9']]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerFinalReportJob(
            'fake-request-id',
            '9.9.9.9',
            'my-server-1',
            "user: root\npass: xyz",
            $bot->chatId() ?? 1,
            5,
            99,
        );
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        $this->assertCount(1, $history);

        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('my-server-1', $body['text']);
        $this->assertStringContainsString('9.9.9.9', $body['text']);
        $this->assertStringContainsString('pass: xyz', $body['text']);
        $this->assertStringContainsString('Iran, Tehran', $body['text']);
        $this->assertStringContainsString('view_server:5:99', json_encode($body['reply_markup']));
    }

    public function test_final_report_job_deletes_and_rebuilds_when_ping_is_not_clean(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
            'api.digitalocean.com/v2/droplets/99' => Http::response([], 204),
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 100, 'name' => 'my-server-1'],
                'links' => ['actions' => [['id' => 555, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerFinalReportJob(
            'fake-request-id',
            '9.9.9.9',
            'my-server-1',
            "user: root\npass: xyz",
            $bot->chatId() ?? 1,
            $panel->id,
            99,
            'nyc1',
            's-1vcpu-1gb',
            'ubuntu-24-04-x64',
            1,
        );
        $job->handle($bot, new CheckHostClient());

        // No prior "best" attempt yet, so this one (99) becomes it and is
        // kept alive — only a fresh candidate is created to test against it,
        // per the "keep the best of two" retry algorithm (see
        // ReplaceServerPingCheckJob for the same logic on the replace flow).
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/droplets/99')
            && $request->method() === 'DELETE');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/droplets'));

        Queue::assertPushed(CreateServerReadyJob::class, function ($job) {
            return $this->prop($job, 'attempt') === 2
                && $this->prop($job, 'bestServerId') === 99
                && $this->prop($job, 'bestIp') === '9.9.9.9'
                && $this->prop($job, 'bestOkCount') === 0;
        });

        // Still retrying — no Telegram message sent yet, that only happens
        // once a clean ping is found or attempts are exhausted.
        $this->assertEmpty($bot->getRequestHistory());
    }

    protected function prop(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    public function test_final_report_job_sends_immediately_and_omits_recreate_button_when_ping_is_clean(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['OK', 0.08]]],
                'ir9.node.check-host.net' => [[['OK', 0.09]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerFinalReportJob(
            'fake-request-id',
            '9.9.9.9',
            'my-server-1',
            "user: root\npass: xyz",
            $bot->chatId() ?? 1,
            5,
            99,
            'nyc1',
            's-1vcpu-1gb',
            'ubuntu-24-04-x64',
            1,
        );
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        $this->assertCount(1, $history);

        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $markup = json_encode($body['reply_markup']);

        $this->assertStringContainsString('view_server:5:99', $markup);
        $this->assertStringNotContainsString('recreate_server', $markup);
    }

    public function test_final_report_job_gives_up_and_offers_manual_recreate_after_max_attempts(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerFinalReportJob(
            'fake-request-id',
            '9.9.9.9',
            'my-server-1',
            "user: root\npass: xyz",
            $bot->chatId() ?? 1,
            5,
            99,
            'nyc1',
            's-1vcpu-1gb',
            'ubuntu-24-04-x64',
            10,
        );
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        $this->assertCount(1, $history);

        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('بعد از 10 تلاش', $body['text']);
        $this->assertStringContainsString('recreate_server:5:99', json_encode($body['reply_markup']));
    }

    public function test_server_ping_check_job_always_reports_clean_result(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['OK', 0.08]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ServerPingCheckJob('fake-request-id', '9.9.9.9', 'my-server-1', $bot->chatId() ?? 1);
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        $this->assertCount(1, $history);

        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('✅', $body['text']);
        $this->assertStringContainsString('my-server-1', $body['text']);
        $this->assertStringContainsString('9.9.9.9', $body['text']);
    }

    public function test_server_ping_check_job_always_reports_bad_result_too(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ServerPingCheckJob('fake-request-id', '9.9.9.9', 'my-server-1', $bot->chatId() ?? 1);
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        $this->assertCount(1, $history);

        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('⚠️', $body['text']);
        $this->assertStringContainsString('no response', $body['text']);
    }
}
