<?php

use App\Console\Commands\CheckServerPingsCommand;
use App\Console\Commands\CheckWireguardLocationsCommand;
use App\Console\Commands\CheckWireguardProfilesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Documents the intended cadence; this project has no `schedule:run` cron
// loop set up, so in production these commands are invoked directly every 3
// minutes via the server's crontab instead of through this schedule.
Schedule::command(CheckServerPingsCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardProfilesCommand::class)->everyTenMinutes();
Schedule::command(CheckWireguardLocationsCommand::class)->everyTenMinutes();
