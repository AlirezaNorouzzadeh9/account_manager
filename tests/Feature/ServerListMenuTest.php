<?php

namespace Tests\Feature;

use App\Jobs\PollProviderActionJob;
use App\Jobs\ServerPingCheckJob;
use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class ServerListMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_list_reboot_flow(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
            'api.digitalocean.com/v2/droplets/42' => Http::response(['droplet' => $droplet]),
            'api.digitalocean.com/v2/droplets/42/actions' => Http::response([
                'action' => ['id' => 555, 'status' => 'in-progress', 'type' => 'reboot'],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('42')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // first grid button = powerOn

        Queue::assertPushed(PollProviderActionJob::class);
    }

    public function test_server_list_shows_datacenter_for_each_server(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();

        // The very last history entry after a callback tap is always the
        // automatic answerCallbackQuery (no "reply_markup" field) — find the
        // last actual sendMessage/editMessageText body instead.
        $markup = null;

        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['reply_markup'])) {
                $markup = $body['reply_markup'];
                break;
            }
        }

        $this->assertStringContainsString('New York 1', json_encode($markup, JSON_UNESCAPED_UNICODE));
    }

    public function test_check_ping_button_starts_a_ping_and_dispatches_the_check_job(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
            'created_by' => 555,
        ]);

        $droplet = [
            'id' => 42,
            'name' => 'my-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '1.2.3.4', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/droplets?*' => Http::response([
                'droplets' => [$droplet],
                'links' => [],
                'meta' => ['total' => 1],
            ]),
            'api.digitalocean.com/v2/droplets/42' => Http::response(['droplet' => $droplet]),
            'check-host.net/check-ping*' => Http::response([
                'ok' => 1,
                'request_id' => 'fake-request-id',
                'nodes' => [],
            ]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('42')->reply();
        $bot->hearCallbackQueryData('x@@@@@@')->reply(); // 7th grid button = checkPing

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'check-ping')
            && str_contains((string) $request->url(), 'host=1.2.3.4'));

        Queue::assertPushed(ServerPingCheckJob::class, function (ServerPingCheckJob $job) {
            $ref = new \ReflectionProperty($job, 'ip');
            $ref->setAccessible(true);

            return $ref->getValue($job) === '1.2.3.4';
        });
    }
}
