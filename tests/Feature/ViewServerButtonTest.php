<?php

namespace Tests\Feature;

use App\Jobs\PollProviderActionJob;
use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class ViewServerButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_job_sends_view_server_button_and_button_opens_detail(): void
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
            'id' => 77,
            'name' => 'created-server-1',
            'status' => 'active',
            'region' => ['slug' => 'nyc1', 'name' => 'New York 1'],
            'size_slug' => 's-1vcpu-1gb',
            'image' => ['distribution' => 'Ubuntu', 'name' => '24.04 x64'],
            'networks' => ['v4' => [['ip_address' => '9.9.9.9', 'type' => 'public']]],
        ];

        Http::fake([
            'api.digitalocean.com/v2/actions/*' => Http::response([
                'action' => ['id' => 555, 'status' => 'completed', 'type' => 'create', 'resource_type' => 'droplet', 'resource_id' => 77],
            ]),
            'api.digitalocean.com/v2/droplets/77' => Http::response(['droplet' => $droplet]),
            'api.digitalocean.com/v2/reserved_ips*' => Http::response(['reserved_ips' => []]),
        ]);

        config(['bot.admins' => ['555']]);
        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 555, is_bot: false, first_name: 'Tester'));

        $job = new PollProviderActionJob(
            $panel->id,
            555,
            $bot->chatId() ?? 123,
            '✅ سرور «created-server-1» با موفقیت ساخته شد.',
            '❌ ساخت سرور ناموفق بود.',
        );
        $job->handle($bot);

        $history = $bot->getRequestHistory();
        $this->assertNotEmpty($history);

        [$request] = array_values(end($history));
        $body = json_decode((string) $request->getBody(), true);

        $this->assertStringContainsString('9.9.9.9', $body['text']);
        $this->assertStringContainsString("view_server:{$panel->id}:77", json_encode($body['reply_markup']));

        // Now simulate the user tapping that button.
        $bot->willStartConversation();
        $bot->hearCallbackQueryData("view_server:{$panel->id}:77")->reply();

        $history2 = $bot->getRequestHistory();
        [$request2] = array_values(end($history2));
        $body2 = json_decode((string) $request2->getBody(), true);

        $this->assertStringContainsString('created-server-1', $body2['text']);
    }
}
