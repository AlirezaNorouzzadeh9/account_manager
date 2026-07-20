<?php

namespace Tests\Feature;

use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class AddPanelOvhFlowTest extends TestCase
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

    protected function fakeOvhAuth(): void
    {
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1' => Http::response([
                'description' => 'My Project',
            ]),
        ]);
    }

    public function test_adding_an_ovh_panel_walks_through_all_four_credential_fields(): void
    {
        $this->fakeOvhAuth();

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن پنل"
        $bot->hearCallbackQueryData('ovh')->reply(); // chooseProvider

        $bot->hearText('OVH Panel 1')->reply(); // receiveName
        $bot->hearText('ak-1')->reply(); // receiveOvhApplicationKey
        $bot->hearText('as-1')->reply(); // receiveOvhApplicationSecret
        $bot->hearText('ck-1')->reply(); // receiveOvhConsumerKey
        $bot->hearText('project-1')->reply(); // receiveOvhServiceName -> validates via account() and creates the panel

        $panel = Panel::firstOrFail();

        $this->assertSame('OVH Panel 1', $panel->name);
        $this->assertSame('ovh', $panel->provider->value);
        $this->assertSame('ck-1', $panel->api_token);
        $this->assertSame('ak-1', $panel->meta['application_key']);
        $this->assertSame('as-1', $panel->meta['application_secret']);
        $this->assertSame('project-1', $panel->meta['service_name']);
        $this->assertSame('My Project', $panel->meta['email']);
        $this->assertSame(555, $panel->created_by);
    }

    public function test_invalid_ovh_credentials_restart_the_chain_instead_of_creating_a_panel(): void
    {
        Http::fake([
            'eu.api.ovh.com/1.0/auth/time' => Http::response('1700000000'),
            'eu.api.ovh.com/1.0/cloud/project/project-1' => Http::response([
                'message' => 'This credential is not valid',
            ], 403),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('ovh')->reply();

        $bot->hearText('OVH Panel 1')->reply();
        $bot->hearText('ak-1')->reply();
        $bot->hearText('as-1')->reply();
        $bot->hearText('bad-ck')->reply();
        $bot->hearText('project-1')->reply(); // fails validation here

        $this->assertSame(0, Panel::count());
    }
}
