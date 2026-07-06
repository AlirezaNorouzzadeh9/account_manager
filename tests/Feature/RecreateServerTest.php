<?php

namespace Tests\Feature;

use App\Jobs\CreateServerFinalReportJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Services\CheckHost\CheckHostClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class RecreateServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_host_client_detects_incomplete_ping(): void
    {
        $client = new CheckHostClient();

        $complete = [
            'ir5.node.check-host.net' => [[['OK', 0.09]]],
            'ir9.node.check-host.net' => [[['OK', 0.10]]],
        ];
        $incomplete = [
            'ir5.node.check-host.net' => [[['OK', 0.09]]],
            'ir9.node.check-host.net' => [[['TIMEOUT', 3.0]]],
        ];

        $this->assertTrue($client->allNodesOk($complete));
        $this->assertFalse($client->allNodesOk($incomplete));
        $this->assertFalse($client->allNodesOk([]));
    }

    public function test_final_report_adds_recreate_button_only_when_ping_incomplete(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerFinalReportJob('req-1', '9.9.9.9', 'srv-1', 'creds', $bot->chatId() ?? 1, 5, 99);
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('recreate_server:5:99', json_encode($body['reply_markup']));
    }

    public function test_final_report_has_no_recreate_button_when_ping_complete(): void
    {
        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['OK', 0.08]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CreateServerFinalReportJob('req-2', '9.9.9.9', 'srv-2', 'creds', $bot->chatId() ?? 1, 5, 100);
        $job->handle($bot, new CheckHostClient());

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringNotContainsString('recreate_server:', json_encode($body['reply_markup']));
    }

    public function test_recreate_conversation_deletes_and_recreates_with_same_spec(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 111,
            'root_password' => 'old-password',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-old',
        ]);

        Http::fake([
            'api.digitalocean.com/v2/droplets/111' => Http::response([], 204),
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 222, 'name' => 'srv-old'],
                'links' => ['actions' => [['id' => 999, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData("recreate_server:{$panel->id}:111")->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/droplets/111'));

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/droplets')
            && ($request['name'] ?? null) === 'srv-old'
            && ($request['region'] ?? null) === 'nyc1');

        $this->assertDatabaseHas('server_secrets', [
            'panel_id' => $panel->id,
            'provider_server_id' => 222,
            'hostname' => 'srv-old',
        ]);
    }
}
