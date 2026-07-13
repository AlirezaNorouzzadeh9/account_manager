<?php

namespace Tests\Feature;

use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class AddPanelAzureFlowTest extends TestCase
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

    protected function fakeAzureAuth(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'management.azure.com/subscriptions/sub-1?*' => Http::response([
                'subscriptionId' => 'sub-1',
                'displayName' => 'My Subscription',
            ]),
        ]);
    }

    public function test_adding_an_azure_panel_walks_through_all_five_credential_fields(): void
    {
        $this->fakeAzureAuth();

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن پنل"
        $bot->hearCallbackQueryData('azure')->reply(); // chooseProvider

        $bot->hearText('Azure Panel 1')->reply(); // receiveName
        $bot->hearText('tenant-1')->reply(); // receiveTenantId
        $bot->hearText('client-1')->reply(); // receiveClientId
        $bot->hearText('secret-1')->reply(); // receiveClientSecret
        $bot->hearText('sub-1')->reply(); // receiveSubscriptionId -> validates via account()
        $bot->hearText('my-rg')->reply(); // receiveResourceGroup -> creates the panel

        $panel = Panel::firstOrFail();

        $this->assertSame('Azure Panel 1', $panel->name);
        $this->assertSame('azure', $panel->provider->value);
        $this->assertSame('secret-1', $panel->api_token);
        $this->assertSame('tenant-1', $panel->meta['tenant_id']);
        $this->assertSame('client-1', $panel->meta['client_id']);
        $this->assertSame('sub-1', $panel->meta['subscription_id']);
        $this->assertSame('my-rg', $panel->meta['resource_group']);
        $this->assertSame('My Subscription', $panel->meta['email']);
        $this->assertSame(555, $panel->created_by);
    }

    public function test_invalid_azure_credentials_restart_the_chain_instead_of_creating_a_panel(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'AADSTS7000215: Invalid client secret.',
            ], 401),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply();
        $bot->hearCallbackQueryData('azure')->reply();

        $bot->hearText('Azure Panel 1')->reply();
        $bot->hearText('tenant-1')->reply();
        $bot->hearText('client-1')->reply();
        $bot->hearText('bad-secret')->reply();
        $bot->hearText('sub-1')->reply(); // fails validation here

        $this->assertSame(0, Panel::count());
    }
}
