<?php

namespace Tests\Feature;

use App\Models\BotUser;
use App\Models\Panel;
use App\Models\ServerSecret;
use App\Models\WireguardLocation;
use App\Models\WireguardProfile;
use App\Telegram\Conversations\AddBotUserConversation;
use App\Telegram\Conversations\UserManagementMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

/**
 * Every allowed user (owner or granted regular user) must only ever see/
 * touch their OWN panels, WireGuard locations, and WireGuard profiles. These
 * tests act as user A (id 111) and try to reach user B's (id 222) resources
 * — both through normal menu listings and through the globally-routed
 * callback_data patterns in routes/telegram.php (view_server, replace_server,
 * recreate_server, delete_old_server, retry_node_pw), which — unlike a
 * regular InlineMenu button — DO get dispatched no matter what was actually
 * rendered to the tapping user, so they're the real place a crafted id could
 * reach another user's data if the ownedBy() checks inside them were ever
 * removed.
 */
class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected const USER_A = 111;

    protected const USER_B = 222;

    protected function botAs(int $telegramId): FakeNutgram
    {
        config(['bot.admins' => [(string) self::USER_A]]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: $telegramId, is_bot: false, first_name: 'Tester'));

        return $bot;
    }

    protected function lastMessageBody(FakeNutgram $bot): array
    {
        foreach (array_reverse($bot->getRequestHistory()) as $item) {
            [$request] = array_values($item);
            $body = json_decode((string) $request->getBody(), true);

            if (isset($body['text'])) {
                return $body;
            }
        }

        return [];
    }

    protected function makePanel(int $ownerId, string $name = 'Panel'): Panel
    {
        return Panel::create([
            'name' => $name,
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
            'created_by' => $ownerId,
        ]);
    }

    public function test_panels_menu_only_lists_the_acting_users_own_panels(): void
    {
        $this->makePanel(self::USER_A, 'A Panel');
        $this->makePanel(self::USER_B, 'B Panel');

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('panels:menu')->reply();

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('A Panel', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('B Panel', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function test_wireguard_locations_list_only_shows_the_acting_users_own_locations(): void
    {
        WireguardLocation::create(['name' => 'a-loc', 'ip' => '1.1.1.1', 'server_public_key' => 'pub-a', 'created_by' => self::USER_A]);
        WireguardLocation::create(['name' => 'b-loc', 'ip' => '2.2.2.2', 'server_public_key' => 'pub-b', 'created_by' => self::USER_B]);

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x')->reply(); // "مدیریت وایرگاردها"

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('a-loc', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('b-loc', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function test_wireguard_profiles_list_only_shows_the_acting_users_own_profiles(): void
    {
        WireguardProfile::create(['name' => 'a-profile', 'private_key' => 'key-a', 'created_by' => self::USER_A]);
        WireguardProfile::create(['name' => 'b-profile', 'private_key' => 'key-b', 'created_by' => self::USER_B]);

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@')->reply(); // "پروفایل‌ها"

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('a-profile', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('b-profile', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    public function test_connect_server_profile_picker_only_offers_the_acting_users_own_profiles(): void
    {
        WireguardProfile::create(['name' => 'a-profile', 'private_key' => 'key-a', 'created_by' => self::USER_A]);
        WireguardProfile::create(['name' => 'b-profile', 'private_key' => 'key-b', 'created_by' => self::USER_B]);

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('server:connect')->reply();
        $bot->hearText('203.0.113.10')->reply();
        $bot->hearText('root')->reply();
        $bot->hearText('super-secret-pass')->reply();

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('a-profile', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('b-profile', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /**
     * "recreate_server:{panelId}:{serverId}" is matched as a global route
     * regardless of what was ever rendered to the tapping user, so a crafted
     * panelId belonging to someone else really does reach
     * RecreateServerConversation — only the ownedBy() check inside
     * confirmRecreate() stands between that and touching user B's server.
     */
    public function test_recreate_server_route_cannot_touch_another_users_server(): void
    {
        $panelB = $this->makePanel(self::USER_B, 'B Panel');
        ServerSecret::create([
            'panel_id' => $panelB->id,
            'provider_server_id' => 111,
            'root_password' => 'pw',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'b-server',
        ]);

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData("recreate_server:{$panelB->id}:111")->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        Http::assertNothingSent();
        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('ذخیره نشده', $body['text']);
    }

    public function test_replace_server_route_cannot_touch_another_users_server(): void
    {
        $panelB = $this->makePanel(self::USER_B, 'B Panel');
        ServerSecret::create([
            'panel_id' => $panelB->id,
            'provider_server_id' => 111,
            'root_password' => 'pw',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'b-server',
        ]);

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData("replace_server:{$panelB->id}:111")->reply();
        $bot->hearCallbackQueryData('yes')->reply();

        Http::assertNothingSent();
        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('ذخیره نشده', $body['text']);
    }

    public function test_delete_old_server_route_cannot_delete_another_users_server(): void
    {
        $panelB = $this->makePanel(self::USER_B, 'B Panel');

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData("delete_old_server:{$panelB->id}:111")->reply();

        Http::assertNothingSent();
        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('این پنل دیگر وجود ندارد', $body['text']);
    }

    public function test_retry_node_pw_route_denies_access_to_another_users_panel(): void
    {
        $panelB = $this->makePanel(self::USER_B, 'B Panel');

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData("retry_node_pw:{$panelB->id}:111")->reply();

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('متعلق به شما نیست', $body['text']);
    }

    public function test_user_not_in_the_allowlist_is_blocked_by_the_middleware(): void
    {
        config(['bot.admins' => [(string) self::USER_A]]);

        /** @var FakeNutgram $bot */
        $bot = $this->app->make(Nutgram::class);
        $bot->setCommonUser(User::make(id: 999999, is_bot: false, first_name: 'Stranger'));
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('اجازه', $body['text']);
        $this->assertDatabaseCount('panels', 0);
    }

    public function test_owner_granted_regular_user_can_use_the_bot(): void
    {
        config(['bot.admins' => [(string) self::USER_A]]);
        BotUser::create(['telegram_id' => self::USER_B, 'label' => 'Friend', 'added_by' => self::USER_A]);

        $bot = $this->botAs(self::USER_B);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('خوش آمدید', $body['text']);
    }

    public function test_non_owner_does_not_see_the_user_management_button(): void
    {
        config(['bot.admins' => [(string) self::USER_A]]);
        BotUser::create(['telegram_id' => self::USER_B, 'label' => 'Friend', 'added_by' => self::USER_A]);

        $bot = $this->botAs(self::USER_B);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();

        $body = $this->lastMessageBody($bot);
        $this->assertStringNotContainsString('مدیریت کاربران', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Defense-in-depth: even if UserManagementMenu were ever reached some
     * other way than tapping the (owner-only) settings button, it re-checks
     * ownership itself at the top of start().
     */
    public function test_user_management_menu_denies_a_non_owner_even_called_directly(): void
    {
        config(['bot.admins' => [(string) self::USER_A]]);
        BotUser::create(['telegram_id' => self::USER_B, 'label' => 'Friend', 'added_by' => self::USER_A]);

        $bot = $this->botAs(self::USER_B);
        $bot->willStartConversation();
        $bot->hearText('/start')->reply();
        // Reuses the /start update's context (correct acting user, no active
        // callback message) — simulates UserManagementMenu being reached some
        // way other than tapping the (owner-only, never-rendered-to-B) button.
        UserManagementMenu::begin($bot);

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('مالکین', $body['text']);
    }

    public function test_add_bot_user_conversation_denies_a_non_owner_even_called_directly(): void
    {
        config(['bot.admins' => [(string) self::USER_A]]);
        BotUser::create(['telegram_id' => self::USER_B, 'label' => 'Friend', 'added_by' => self::USER_A]);

        $bot = $this->botAs(self::USER_B);
        $bot->willStartConversation();
        $bot->hearText('/start')->reply();
        AddBotUserConversation::begin($bot);

        $body = $this->lastMessageBody($bot);
        $this->assertStringContainsString('مالکین', $body['text']);
        $this->assertDatabaseCount('bot_users', 1); // still just the seeded USER_B row, nothing added
    }

    public function test_owner_can_grant_access_via_the_user_management_menu(): void
    {
        config(['bot.admins' => [(string) self::USER_A]]);

        $bot = $this->botAs(self::USER_A);
        $bot->willStartConversation();

        $bot->hearText('/start')->reply();
        $bot->hearCallbackQueryData('settings:menu')->reply();
        $bot->hearCallbackQueryData('x@@')->reply(); // "👥 مدیریت کاربران" (3rd row on an owner's settings menu)
        $bot->hearCallbackQueryData('x')->reply(); // "➕ افزودن کاربر"
        $bot->hearText((string) self::USER_B)->reply();
        $bot->hearText('New Friend')->reply();

        $this->assertDatabaseHas('bot_users', [
            'telegram_id' => self::USER_B,
            'label' => 'New Friend',
            'added_by' => self::USER_A,
        ]);
    }
}
