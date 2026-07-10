<?php

namespace Tests\Feature;

use App\Jobs\CreateServerFinalReportJob;
use App\Jobs\CreateServerReadyJob;
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
        ];

        $formatted = $client->formatResult($result);

        $this->assertStringContainsString('Iran, Tehran', $formatted);
        $this->assertStringContainsString('تهران', $formatted);
        $this->assertStringContainsString('2/2', $formatted);
        $this->assertStringContainsString('92ms', $formatted); // avg of 91/93 -> 92
        $this->assertStringContainsString('Iran, Khonj', $formatted);
        $this->assertStringContainsString('خنج', $formatted);
        $this->assertStringContainsString('0/1', $formatted);
        $this->assertStringContainsString('no response', $formatted);
        $this->assertStringContainsString('Iran, Qom', $formatted);
        $this->assertStringContainsString('قم', $formatted);
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
}
