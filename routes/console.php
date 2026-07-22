<?php

use App\Console\Commands\CheckServerPingsCommand;
use App\Console\Commands\CheckWireguardProfilesCommand;
use App\Console\Commands\CheckWireguardTunnelsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Documents the intended cadence; this project has no `schedule:run` cron
// loop set up, so in production these commands are invoked directly via the
// server's crontab instead of through this schedule — check-pings/
// check-profiles every 3 minutes, check-tunnels every 10.
//
// A WireguardLocation's ip is deliberately only ever changed by
// wireguard:check-tunnels' local tunnel probe, never by an Iran ping check —
// an Iran-only ping failure doesn't mean the tunnel is actually down (a
// prior wireguard:check-locations command did exactly that and was removed
// after it swapped a location's ip based on nothing but a flaky Iran ping).
Schedule::command(CheckServerPingsCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardProfilesCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardTunnelsCommand::class)->everyTenMinutes();
