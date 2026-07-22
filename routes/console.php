<?php

use App\Console\Commands\CheckServerPingsCommand;
use App\Console\Commands\CheckWireguardLocationsCommand;
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
// check-profiles/check-locations every 3 minutes, check-tunnels every 10.
Schedule::command(CheckServerPingsCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardProfilesCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardLocationsCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardTunnelsCommand::class)->everyTenMinutes();
