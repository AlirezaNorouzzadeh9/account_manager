<?php

namespace App\Console\Commands;

use App\Jobs\CheckWireguardTunnelsJob;
use Illuminate\Console\Command;

/**
 * Runs every 10 minutes (see the server's crontab): queues a local probe of
 * every WireGuard tunnel currently up on THIS server (see
 * CheckWireguardTunnelsJob) — a single check covering every interface at
 * once, unlike wireguard:check-locations which dispatches one job per
 * location.
 */
class CheckWireguardTunnelsCommand extends Command
{
    protected $signature = 'wireguard:check-tunnels';

    protected $description = 'Queue a local probe of every WireGuard tunnel currently up on this server';

    public function handle(): int
    {
        CheckWireguardTunnelsJob::dispatch();

        $this->info('['.now().'] queued a local wireguard tunnel check.');

        return self::SUCCESS;
    }
}
