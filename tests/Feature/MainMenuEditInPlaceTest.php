<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/**
 * Tapping a main-menu button used to leave the "/start" message sitting in
 * the chat and send a brand new message for the next screen. It should
 * instead edit that same message in place.
 */
class MainMenuEditInPlaceTest extends TestCase
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

    /** @return array{path: string, body: array} */
    protected function lastRequest(FakeNutgram $bot): array
    {
        $history = $bot->getRequestHistory();
        [$request] = array_values(end($history));

        return [
            'path' => $request->getUri()->getPath(),
            'body' => json_decode((string) $request->getBody(), true),
        ];
    }

    public function test_tapping_panels_menu_edits_the_start_message_instead_of_sending_a_new_one(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();

        ['path' => $path, 'body' => $body] = $this->lastRequest($bot);

        $this->assertStringContainsString('editMessageText', $path);
        $this->assertArrayHasKey('message_id', $body);
        $this->assertStringContainsString('هنوز هیچ پنلی', $body['text']);
    }

    public function test_tapping_create_server_edits_the_start_message_instead_of_sending_a_new_one(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:create')->reply();

        ['path' => $path, 'body' => $body] = $this->lastRequest($bot);

        $this->assertStringContainsString('editMessageText', $path);
        $this->assertArrayHasKey('message_id', $body);
    }

    public function test_tapping_settings_edits_the_start_message_instead_of_sending_a_new_one(): void
    {
        $bot = $this->bot();
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();

        ['path' => $path, 'body' => $body] = $this->lastRequest($bot);

        $this->assertStringContainsString('editMessageText', $path);
        $this->assertArrayHasKey('message_id', $body);
        $this->assertStringContainsString('تنظیمات', $body['text']);
    }
}
