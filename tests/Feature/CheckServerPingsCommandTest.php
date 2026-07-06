<?php

namespace Tests\Feature;

use App\Jobs\CheckServerPingJob;
use App\Models\Panel;
use App\Models\ServerSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckServerPingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_a_ping_check_for_every_fully_specced_server_on_an_active_panel(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => true,
        ]);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 111,
            'root_password' => 'pw',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-1',
        ]);

        // No build spec saved (e.g. pre-existing server) — must be skipped.
        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 222,
            'root_password' => 'pw',
        ]);

        Http::fake([
            'api.digitalocean.com/v2/droplets/111' => Http::response([
                'droplet' => ['networks' => ['v4' => [['ip_address' => '9.9.9.9', 'type' => 'public']]]],
            ]),
        ]);

        $this->artisan('servers:check-pings')->assertExitCode(0);

        Queue::assertPushed(CheckServerPingJob::class, 1);
        Queue::assertPushed(CheckServerPingJob::class, function ($job) use ($panel) {
            $property = new \ReflectionProperty($job, 'panelId');
            $property->setAccessible(true);

            return $property->getValue($job) === $panel->id;
        });
    }

    public function test_skips_servers_on_an_inactive_panel(): void
    {
        Queue::fake();

        $panel = Panel::create([
            'name' => 'My DO Panel',
            'provider' => 'digitalocean',
            'api_token' => 'fake-token',
            'meta' => [],
            'is_active' => false,
        ]);

        ServerSecret::create([
            'panel_id' => $panel->id,
            'provider_server_id' => 111,
            'root_password' => 'pw',
            'region' => 'nyc1',
            'size' => 's-1vcpu-1gb',
            'image' => 'ubuntu-24-04-x64',
            'hostname' => 'srv-1',
        ]);

        $this->artisan('servers:check-pings')->assertExitCode(0);

        Queue::assertNotPushed(CheckServerPingJob::class);
    }
}
