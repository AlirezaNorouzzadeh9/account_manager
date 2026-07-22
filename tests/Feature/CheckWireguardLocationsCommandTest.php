<?php

namespace Tests\Feature;

use App\Jobs\CheckWireguardLocationJob;
use App\Models\WireguardLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckWireguardLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_a_check_for_every_location_that_has_a_hostname(): void
    {
        Queue::fake();

        $withHostname = WireguardLocation::create([
            'name' => 'germany', 'ip' => '1.2.3.4', 'hostname' => 'de.example.com',
            'server_public_key' => 'pub', 'created_by' => 555,
        ]);
        WireguardLocation::create([
            'name' => 'france', 'ip' => '5.6.7.8', 'hostname' => null,
            'server_public_key' => 'pub', 'created_by' => 555,
        ]);

        $this->artisan('wireguard:check-locations')->assertExitCode(0);

        Queue::assertPushed(CheckWireguardLocationJob::class, 1);
        Queue::assertPushed(CheckWireguardLocationJob::class, function ($job) use ($withHostname) {
            $property = new \ReflectionProperty($job, 'locationId');
            $property->setAccessible(true);

            return $property->getValue($job) === $withHostname->id;
        });
    }

    public function test_does_nothing_when_no_location_has_a_hostname(): void
    {
        Queue::fake();

        WireguardLocation::create([
            'name' => 'germany', 'ip' => '1.2.3.4', 'hostname' => null,
            'server_public_key' => 'pub', 'created_by' => 555,
        ]);

        $this->artisan('wireguard:check-locations')->assertExitCode(0);

        Queue::assertNotPushed(CheckWireguardLocationJob::class);
    }
}
