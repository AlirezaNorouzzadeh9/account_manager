<?php

namespace Tests\Feature;

use App\Models\WireguardProfile;
use App\Services\Pasarguard\PasarguardNodeReconnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasarguardNodeReconnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_string_when_no_domain_was_used(): void
    {
        $line = (new PasarguardNodeReconnector())->reminder(null, null);

        $this->assertSame('', $line);
    }

    public function test_falls_back_to_manual_reminder_when_profile_has_no_core_id(): void
    {
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'k']);

        $line = (new PasarguardNodeReconnector())->reminder('germany.node.pcbot.top', $profile);

        $this->assertStringContainsString('دستی', $line);
    }

    public function test_falls_back_to_manual_reminder_when_panel_credentials_are_not_configured(): void
    {
        config(['pasarguard.panel.url' => null]);
        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'k', 'core_id' => 268]);

        $line = (new PasarguardNodeReconnector())->reminder('germany.node.pcbot.top', $profile);

        $this->assertStringContainsString('دستی', $line);
    }

    public function test_auto_reconnects_the_node_when_core_id_and_panel_credentials_are_set(): void
    {
        config([
            'pasarguard.panel.url' => 'https://panel.test',
            'pasarguard.panel.username' => 'bots',
            'pasarguard.panel.password' => 'secret',
        ]);

        Http::fake([
            'panel.test/api/admin/token' => Http::response(['access_token' => 'tok-123', 'token_type' => 'bearer']),
            'panel.test/api/node/268/reconnect' => Http::response([]),
        ]);

        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'k', 'core_id' => 268]);

        $line = (new PasarguardNodeReconnector())->reminder('germany.node.pcbot.top', $profile);

        $this->assertStringContainsString('خودکار', $line);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/api/node/268/reconnect'));
    }

    public function test_falls_back_to_manual_reminder_when_the_reconnect_call_fails(): void
    {
        config([
            'pasarguard.panel.url' => 'https://panel.test',
            'pasarguard.panel.username' => 'bots',
            'pasarguard.panel.password' => 'secret',
        ]);

        Http::fake([
            'panel.test/api/admin/token' => Http::response(['detail' => 'bad credentials'], 401),
        ]);

        $profile = WireguardProfile::create(['name' => 'germany', 'private_key' => 'k', 'core_id' => 268]);

        $line = (new PasarguardNodeReconnector())->reminder('germany.node.pcbot.top', $profile);

        $this->assertStringContainsString('ناموفق', $line);
        $this->assertStringContainsString('دستی', $line);
    }
}
