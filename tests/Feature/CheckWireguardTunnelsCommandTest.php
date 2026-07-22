<?php

namespace Tests\Feature;

use App\Jobs\CheckWireguardTunnelsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckWireguardTunnelsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_a_single_tunnel_check(): void
    {
        Queue::fake();

        $this->artisan('wireguard:check-tunnels')->assertExitCode(0);

        Queue::assertPushed(CheckWireguardTunnelsJob::class, 1);
    }
}
