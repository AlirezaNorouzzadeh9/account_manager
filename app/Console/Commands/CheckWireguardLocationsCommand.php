<?php

namespace App\Console\Commands;

use App\Jobs\CheckWireguardLocationJob;
use App\Models\WireguardLocation;
use Illuminate\Console\Command;

/**
 * Runs every 3 minutes (see the server's crontab): queues an Iran ping
 * check for every WireGuard location that has a "hostname" set (see
 * CheckWireguardLocationJob) — locations without one are skipped, same as
 * wireguard:check-profiles skips a profile with no own_ip.
 */
class CheckWireguardLocationsCommand extends Command
{
    protected $signature = 'wireguard:check-locations';

    protected $description = "Queue an Iran ping check for every WireGuard location that has a hostname set";

    public function handle(): int
    {
        $locations = WireguardLocation::whereNotNull('hostname')->get();

        foreach ($locations as $location) {
            CheckWireguardLocationJob::dispatch($location->id);
        }

        $this->info("[".now()."] queued {$locations->count()} wireguard location check(s).");

        return self::SUCCESS;
    }
}
