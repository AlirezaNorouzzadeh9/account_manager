<?php

use App\Console\Commands\CheckServerPingsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Documents the intended cadence; this project has no `schedule:run` cron
// loop set up, so in production this command is invoked directly every 10
// minutes via the server's crontab instead of through this schedule.
Schedule::command(CheckServerPingsCommand::class)->everyTenMinutes();
