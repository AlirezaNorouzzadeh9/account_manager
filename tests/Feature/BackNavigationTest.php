<?php

namespace Tests\Feature;

use App\Models\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class BackNavigationTest extends TestCase
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

    /**
     * The last history entry after a callback tap is always the automatic
     * answerCallbackQuery (no "text" field) — find the last sendMessage/
     * editMessageText body instead.
     */
    protected function lastMessageText(FakeNutgram $bot): string
    {
        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['text'])) {
                return $body['text'];
            }
        }

        return '';
    }

    public function test_add_panel_back_button_returns_to_panels_menu_not_start(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن پنل"
        $bot->hearCallbackQueryData('x')->reply(); // "🔙 بازگشت" inside AddPanelConversation

        // Should land back on the panels list (not the /start main menu).
        $this->assertStringContainsString('هنوز هیچ پنلی', $this->lastMessageText($bot));
    }

    public function test_server_list_panel_choice_back_returns_to_panel_list_not_start(): void
    {
        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => 555,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'api.digitalocean.com/v2/droplets?*' => \Illuminate\Support\Facades\Http::response([
                'droplets' => [], 'links' => [], 'meta' => ['total' => 0],
            ]),
        ]);

        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:list')->reply();
        $bot->hearCallbackQueryData("{$panel->id}")->reply(); // choose the panel -> empty server list
        $bot->hearCallbackQueryData('x')->reply(); // "🔙 بازگشت" -> back to panel choice

        $this->assertStringContainsString('کدام پنل', $this->lastMessageText($bot));
    }
}
