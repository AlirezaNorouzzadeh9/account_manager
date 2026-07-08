<?php

namespace Tests\Feature;

use App\Jobs\CreateServerReadyJob;
use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function bot(): FakeNutgram
    {
        config(['bot.admins' => ['555']]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));

        return $bot;
    }

    public function test_add_panel_conversation_creates_panel(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response([
                'account' => ['email' => 'owner@example.com', 'uuid' => 'abc-uuid'],
            ]),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('digitalocean')->reply();
        $bot->hearText('My DO Panel')->reply();
        $bot->hearText('dop_v1_fake_token_for_testing')->reply();

        $this->assertDatabaseHas('panels', [
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
        ]);

        $panel = Panel::first();
        $this->assertSame('owner@example.com', $panel->meta['email']);
        $this->assertSame('dop_v1_fake_token_for_testing', $panel->api_token);
    }

    public function test_add_panel_conversation_creates_a_linode_panel_with_the_linode_token_url(): void
    {
        Http::fake([
            'api.linode.com/v4/account' => Http::response(['email' => 'owner@example.com']),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('linode')->reply();
        $bot->hearText('My Linode Panel')->reply();

        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertStringContainsString('cloud.linode.com/profile/tokens', $body['text']);

        $bot->hearText('linode_fake_token_for_testing')->reply();

        $this->assertDatabaseHas('panels', [
            'name' => 'My Linode Panel',
            'provider' => 'linode',
        ]);

        $panel = Panel::where('name', 'My Linode Panel')->first();
        $this->assertSame('owner@example.com', $panel->meta['email']);
    }

    public function test_rename_panel_updates_name(): void
    {
        $panel = Panel::create([
            'name' => 'Old Name',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData((string) $panel->id)->reply(); // showPanel
        $bot->hearCallbackQueryData((string) $panel->id)->reply(); // "✏️ ویرایش نام" (first id-based button on this screen)
        $bot->hearText('New Name')->reply();

        $this->assertSame('New Name', $panel->fresh()->name);
    }

    public function test_create_server_conversation_dispatches_ready_job(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => ['email' => 'owner@example.com'],
            'is_active' => true,
        ]);

        Http::fake([
            'api.digitalocean.com/v2/regions*' => Http::response([
                'regions' => [
                    ['slug' => 'nyc1', 'name' => 'New York 1', 'available' => true, 'sizes' => [], 'features' => []],
                ],
            ]),
            'api.digitalocean.com/v2/sizes*' => Http::response([
                'sizes' => [[
                    'slug' => 's-1vcpu-1gb', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25,
                    'transfer' => 1, 'price_monthly' => 6, 'price_hourly' => 0.009,
                    'regions' => ['nyc1'], 'available' => true,
                ]],
            ]),
            'api.digitalocean.com/v2/images*' => Http::response([
                'images' => [[
                    'id' => 1, 'name' => '24.04 x64', 'distribution' => 'Ubuntu',
                    'slug' => 'ubuntu-24-04-x64', 'public' => true, 'regions' => ['nyc1'],
                ]],
            ]),
            'api.digitalocean.com/v2/droplets' => Http::response([
                'droplet' => ['id' => 123, 'name' => 'my-server-1'],
                'links' => ['actions' => [['id' => 999, 'rel' => 'create', 'href' => '']]],
            ]),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:create')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply();
        $bot->hearCallbackQueryData('nyc1')->reply();
        $bot->hearCallbackQueryData('s-1vcpu-1gb')->reply();
        $bot->hearCallbackQueryData('ubuntu-24-04-x64')->reply();
        $bot->hearText('my-server-1')->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        Queue::assertPushed(CreateServerReadyJob::class);
    }
}
