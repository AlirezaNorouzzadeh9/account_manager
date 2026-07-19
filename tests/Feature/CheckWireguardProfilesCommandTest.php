<?php

namespace Tests\Feature;

use App\Jobs\CheckWireguardProfileJob;
use App\Models\WireguardProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckWireguardProfilesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_a_check_for_every_profile_when_dns_is_configured(): void
    {
        Queue::fake();
        config(['dns.cloudflare.zone_id' => 'zone-1', 'dns.cloudflare.api_token' => 'cf-token']);

        $profile = WireguardProfile::create(['name' => 'server1', 'private_key' => 'priv', 'created_by' => 555]);

        $this->artisan('wireguard:check-profiles')->assertExitCode(0);

        Queue::assertPushed(CheckWireguardProfileJob::class, function ($job) use ($profile) {
            $property = new \ReflectionProperty($job, 'profileId');
            $property->setAccessible(true);

            return $property->getValue($job) === $profile->id;
        });
    }

    public function test_does_nothing_when_dns_is_not_configured(): void
    {
        Queue::fake();
        config(['dns.cloudflare.zone_id' => null, 'dns.cloudflare.api_token' => null]);

        WireguardProfile::create(['name' => 'server1', 'private_key' => 'priv', 'created_by' => 555]);

        $this->artisan('wireguard:check-profiles')->assertExitCode(0);

        Queue::assertNotPushed(CheckWireguardProfileJob::class);
    }
}
