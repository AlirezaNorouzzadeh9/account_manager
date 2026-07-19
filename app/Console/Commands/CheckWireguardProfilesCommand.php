<?php

namespace App\Console\Commands;

use App\Jobs\CheckWireguardProfileJob;
use App\Models\WireguardProfile;
use Illuminate\Console\Command;

/**
 * Runs every 10 minutes (see the server's crontab): queues a domain-based
 * Iran ping check for every WireGuard profile (see CheckWireguardProfileJob)
 * — independent of servers:check-pings, since a profile's domain may point
 * at an IP no server in this bot is tracking at all.
 */
class CheckWireguardProfilesCommand extends Command
{
    protected $signature = 'wireguard:check-profiles';

    protected $description = "Queue a domain-based Iran ping check for every WireGuard profile's own domain";

    public function handle(): int
    {
        if (! filled(config('dns.cloudflare.zone_id')) || ! filled(config('dns.cloudflare.api_token'))) {
            $this->info("[".now()."] Cloudflare not configured — skipped.");

            return self::SUCCESS;
        }

        $profiles = WireguardProfile::all();

        foreach ($profiles as $profile) {
            CheckWireguardProfileJob::dispatch($profile->id);
        }

        $this->info("[".now()."] queued {$profiles->count()} wireguard profile check(s).");

        return self::SUCCESS;
    }
}
