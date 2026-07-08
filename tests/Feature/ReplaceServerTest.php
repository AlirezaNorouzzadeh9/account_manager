<?php

namespace Tests\Feature;

use App\Jobs\CheckServerPingJob;
use App\Jobs\CreateServerFinalReportJob;
use App\Jobs\CreateServerReadyJob;
use App\Jobs\DeleteOldServerJob;
use App\Jobs\ReplaceServerFinishJob;
use App\Jobs\ReplaceServerPingCheckJob;
use App\Jobs\ReplaceServerPollJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardProfile;
use App\Services\CheckHost\CheckHostClient;
use App\Services\Pasarguard\PasarguardNodeInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class ReplaceServerTest extends TestCase
{
    use RefreshDatabase;

    protected function prop(object $object, string $name): mixed
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    protected function makePanelAndSecret(int|string $serverId = 111): array
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        $secret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => $serverId,
            'root_password' => 'old-password',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-old',
        ]);

        return [$panel, $secret];
    }

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

    public function test_final_report_adds_recreate_button_when_ping_incomplete(): void
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

    public function test_final_report_adds_recreate_button_even_when_ping_complete(): void
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

        $this->assertStringContainsString('recreate_server:5:100', json_encode($body['reply_markup']));
    }

    public function test_recreate_conversation_deletes_then_recreates_with_no_node_or_wireguard(): void
    {
        Queue::fake();
        [$panel, ] = $this->makePanelAndSecret(111);

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

        // The normal create pipeline, NOT the node/WireGuard-reinstalling replace flow.
        Queue::assertPushed(CreateServerReadyJob::class);
        Queue::assertNotPushed(ReplaceServerPollJob::class);
        Queue::assertNotPushed(ReplaceServerFinishJob::class);
    }

    public function test_confirming_replace_creates_a_new_server_without_touching_the_old_one(): void
    {
        Queue::fake();
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
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
        $bot->hearCallbackQueryData("replace_server:{$panel->id}:111")->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/droplets')
            && ($request['name'] ?? null) === 'srv-old'
            && ($request['region'] ?? null) === 'nyc1');

        $this->assertDatabaseHas('server_secrets', [
            'panel_id' => $panel->id,
            'provider_server_id' => 222,
            'hostname' => 'srv-old',
        ]);

        Queue::assertPushed(ReplaceServerPollJob::class, function ($job) use ($panel) {
            return $this->prop($job, 'oldPanelId') === $panel->id
                && $this->prop($job, 'oldServerId') === '111'
                && $this->prop($job, 'newServerActionId') === 999
                && $this->prop($job, 'attempt') === 1;
        });
    }

    public function test_poll_job_dispatches_ping_check_once_action_completes(): void
    {
        Queue::fake();
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'api.digitalocean.com/v2/actions/999' => Http::response(['action' => ['id' => 999, 'status' => 'completed', 'resource_id' => 222]]),
            'api.digitalocean.com/v2/droplets/222' => Http::response(['droplet' => ['networks' => ['v4' => [['ip_address' => '9.9.9.9', 'type' => 'public']]]]]),
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'req-abc']),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerPollJob($panel->id, '111', 999, 'srv-old', 'nyc1', 's-1vcpu-1gb', 'ubuntu-24-04-x64', null, $bot->chatId() ?? 1, 1);
        $job->handle($bot, new CheckHostClient());

        Queue::assertPushed(ReplaceServerPingCheckJob::class, function ($job) {
            return $this->prop($job, 'requestId') === 'req-abc'
                && $this->prop($job, 'newServerId') === '222'
                && $this->prop($job, 'newIp') === '9.9.9.9'
                && $this->prop($job, 'attempt') === 1;
        });
    }

    public function test_ping_check_dispatches_finish_job_when_ping_is_clean(): void
    {
        Queue::fake();
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['OK', 0.08]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerPingCheckJob('req-abc', $panel->id, '111', '222', '9.9.9.9', 'srv-old', 'nyc1', 's-1vcpu-1gb', 'ubuntu-24-04-x64', null, $bot->chatId() ?? 1, 1);
        $job->handle($bot, new CheckHostClient());

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');

        Queue::assertPushed(ReplaceServerFinishJob::class, function ($job) {
            return $this->prop($job, 'newServerId') === '222' && $this->prop($job, 'newIp') === '9.9.9.9';
        });
    }

    public function test_ping_check_deletes_and_retries_when_ping_bad_and_attempts_remain(): void
    {
        Queue::fake();
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
            'api.digitalocean.com/v2/droplets/222' => Http::response([], 204),
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 333, 'name' => 'srv-old'],
                'links' => ['actions' => [['id' => 1000, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerPingCheckJob('req-abc', $panel->id, '111', '222', '9.9.9.9', 'srv-old', 'nyc1', 's-1vcpu-1gb', 'ubuntu-24-04-x64', null, $bot->chatId() ?? 1, 1);
        $job->handle($bot, new CheckHostClient());

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/droplets/222'));

        Queue::assertPushed(ReplaceServerPollJob::class, function ($job) {
            return $this->prop($job, 'newServerActionId') === 1000 && $this->prop($job, 'attempt') === 2;
        });
    }

    public function test_ping_check_gives_up_after_max_attempts_without_touching_old_server(): void
    {
        Queue::fake();
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerPingCheckJob(
            'req-abc', $panel->id, '111', '222', '9.9.9.9', 'srv-old', 'nyc1', 's-1vcpu-1gb', 'ubuntu-24-04-x64', null,
            $bot->chatId() ?? 1, ReplaceServerPollJob::MAX_ATTEMPTS,
        );
        $job->handle($bot, new CheckHostClient());

        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        Queue::assertNotPushed(ReplaceServerPollJob::class);
        Queue::assertNotPushed(ReplaceServerFinishJob::class);

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('بعد از', $body['text']);
    }

    public function test_finish_job_applies_wireguard_profile_and_schedules_auto_delete_on_success(): void
    {
        Queue::fake();

        [$panel, ] = $this->makePanelAndSecret(111);
        $profile = WireguardProfile::create(['name' => 'Profile 1', 'private_key' => 'fake-private-key']);

        $newSecret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 222,
            'root_password' => 'new-password',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-old',
        ]);

        $installer = \Mockery::mock(PasarguardNodeInstaller::class);
        $installer->shouldReceive('install')
            ->once()
            ->with('9.9.9.9', 'root', 'new-password', 'fake-private-key')
            ->andReturn(['success' => true, 'message' => 'نود پاسارگارد با موفقیت نصب و اجرا شد.', 'log' => '', 'cert' => 'fake-cert', 'domain' => null, 'dns_warning' => null]);
        $installer->shouldReceive('syncProfileDns')->once()->with('Profile 1', '9.9.9.9')->andReturn(null);
        $this->app->instance(PasarguardNodeInstaller::class, $installer);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerFinishJob($panel->id, '111', '222', '9.9.9.9', $profile->id, $bot->chatId() ?? 1);
        $job->handle($bot, $this->app->make(PasarguardNodeInstaller::class));

        $this->assertSame($profile->id, $newSecret->fresh()->wireguard_profile_id);

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('نصب و اجرا شد', $body['text']);
        $this->assertStringContainsString('خودکار حذف', $body['text']);
        $this->assertStringNotContainsString("delete_old_server:{$panel->id}:111", json_encode($body['reply_markup']));

        // Profile had no core_id yet, so there's no prior node to replace —
        // just the server itself gets scheduled for deletion.
        Queue::assertPushed(DeleteOldServerJob::class, function ($job) use ($panel) {
            return $this->prop($job, 'panelId') === $panel->id
                && $this->prop($job, 'serverId') === '111'
                && $this->prop($job, 'oldNodeId') === null;
        });
    }

    public function test_finish_job_creates_a_new_panel_node_and_schedules_old_node_deletion(): void
    {
        Queue::fake();

        config([
            'pasarguard.panel.url' => 'https://panel.test',
            'pasarguard.panel.username' => 'bots',
            'pasarguard.panel.password' => 'secret',
            'pasarguard.api_key' => 'fixed-api-key',
        ]);

        [$panel, ] = $this->makePanelAndSecret(111);
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'fake-private-key', 'core_id' => 268]);

        $newSecret = ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 222,
            'root_password' => 'new-password',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-old',
        ]);

        $installer = \Mockery::mock(PasarguardNodeInstaller::class);
        $installer->shouldReceive('install')
            ->once()
            ->with('9.9.9.9', 'root', 'new-password', 'fake-private-key')
            ->andReturn(['success' => true, 'message' => 'نود پاسارگارد با موفقیت نصب و اجرا شد.', 'log' => '', 'cert' => 'fake-cert-pem', 'domain' => null, 'dns_warning' => null]);
        $installer->shouldReceive('syncProfileDns')
            ->once()
            ->with('germany', '9.9.9.9')
            ->andReturn(['domain' => 'germany.node.pcbot.top', 'error' => null]);
        $this->app->instance(PasarguardNodeInstaller::class, $installer);

        Http::fake([
            'panel.test/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer']),
            'panel.test/api/node/268' => Http::response(['id' => 268, 'core_config_id' => 7]),
            'panel.test/api/node' => Http::response(['id' => 999]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerFinishJob($panel->id, '111', '222', '9.9.9.9', $profile->id, $bot->chatId() ?? 1);
        $job->handle($bot, $this->app->make(PasarguardNodeInstaller::class));

        $this->assertSame(999, $profile->fresh()->core_id);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/api/node')
            && $request->method() === 'POST'
            && ($request['address'] ?? null) === '9.9.9.9'
            && ($request['core_config_id'] ?? null) === 7
            && ($request['server_ca'] ?? null) === 'fake-cert-pem'
            && ($request['api_key'] ?? null) === 'fixed-api-key');

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('نود جدید', $body['text']);
        $this->assertStringContainsString('سرور و نود قبلی', $body['text']);
        $this->assertStringContainsString('germany.node.pcbot.top', $body['text']);

        Queue::assertPushed(DeleteOldServerJob::class, function ($job) use ($panel) {
            return $this->prop($job, 'panelId') === $panel->id
                && $this->prop($job, 'serverId') === '111'
                && $this->prop($job, 'oldNodeId') === 268;
        });
    }

    public function test_finish_job_keeps_manual_delete_confirmation_when_install_fails(): void
    {
        Queue::fake();

        [$panel, ] = $this->makePanelAndSecret(111);
        $profile = WireguardProfile::create(['name' => 'Profile 1', 'private_key' => 'fake-private-key']);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 222,
            'root_password' => 'new-password',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-old',
        ]);

        $installer = \Mockery::mock(PasarguardNodeInstaller::class);
        $installer->shouldReceive('install')
            ->once()
            ->with('9.9.9.9', 'root', 'new-password', 'fake-private-key')
            ->andReturn(['success' => false, 'message' => 'نصب نود ناموفق بود.', 'log' => 'boom', 'cert' => '', 'domain' => null, 'dns_warning' => null]);
        $this->app->instance(PasarguardNodeInstaller::class, $installer);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new ReplaceServerFinishJob($panel->id, '111', '222', '9.9.9.9', $profile->id, $bot->chatId() ?? 1);
        $job->handle($bot, $this->app->make(PasarguardNodeInstaller::class));

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('ناموفق بود', $body['text']);
        $this->assertStringContainsString("delete_old_server:{$panel->id}:111", json_encode($body['reply_markup']));

        Queue::assertNotPushed(DeleteOldServerJob::class);
    }

    public function test_delete_old_server_job_deletes_the_server_and_notifies(): void
    {
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'api.digitalocean.com/v2/droplets/111' => Http::response([], 204),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new DeleteOldServerJob($panel->id, '111', $bot->chatId() ?? 1);
        $job->handle($bot);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/droplets/111'));

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('خودکار حذف شد', $body['text']);
    }

    public function test_delete_old_server_job_also_deletes_the_old_panel_node_when_given_an_id(): void
    {
        config([
            'pasarguard.panel.url' => 'https://panel.test',
            'pasarguard.panel.username' => 'bots',
            'pasarguard.panel.password' => 'secret',
        ]);

        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'api.digitalocean.com/v2/droplets/111' => Http::response([], 204),
            'panel.test/api/admin/token' => Http::response(['access_token' => 'tok', 'token_type' => 'bearer']),
            'panel.test/api/node/268' => Http::response([], 204),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new DeleteOldServerJob($panel->id, '111', $bot->chatId() ?? 1, 268);
        $job->handle($bot);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/api/node/268'));

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('خودکار حذف شد', $body['text']);
        $this->assertStringContainsString('نود قبلی', $body['text']);
    }

    public function test_delete_old_server_job_reports_provider_failure(): void
    {
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'api.digitalocean.com/v2/droplets/111' => Http::response(['message' => 'not found'], 404),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new DeleteOldServerJob($panel->id, '111', $bot->chatId() ?? 1);
        $job->handle($bot);

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('ناموفق بود', $body['text']);
    }

    public function test_delete_old_server_route_deletes_the_old_server(): void
    {
        [$panel, ] = $this->makePanelAndSecret(111);

        Http::fake([
            'api.digitalocean.com/v2/droplets/111' => Http::response([], 204),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData("delete_old_server:{$panel->id}:111")->reply();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), '/droplets/111'));
    }

    public function test_check_server_ping_job_alerts_admins_only_when_ping_fails(): void
    {
        config(['bot.admins' => ['555']]);

        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'req-xyz']),
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['TIMEOUT', 3.0]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CheckServerPingJob(1, '111', '9.9.9.9', 'srv-old');
        $job->handle(new CheckHostClient(), $bot);

        $history = $bot->getRequestHistory();
        $this->assertNotEmpty($history);
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame(555, $body['chat_id']);
        $this->assertStringContainsString('replace_server:1:111', json_encode($body['reply_markup']));
    }

    public function test_check_server_ping_job_stays_silent_when_ping_is_clean(): void
    {
        Http::fake([
            'check-host.net/check-ping*' => Http::response(['ok' => 1, 'request_id' => 'req-xyz']),
            'check-host.net/check-result/*' => Http::response([
                'ir5.node.check-host.net' => [[['OK', 0.08]]],
            ]),
        ]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);

        $job = new CheckServerPingJob(1, '111', '9.9.9.9', 'srv-old');
        $job->handle(new CheckHostClient(), $bot);

        $this->assertEmpty($bot->getRequestHistory());
    }
}
