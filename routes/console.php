<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Shared hosting: one cron entry runs the scheduler every minute
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
*/

// Process queued jobs in short bursts (no long-running worker on shared hosting).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

// Nightly: refresh drivers, then fetch recent activity and evaluate the last 7 days.
Schedule::command('tacho:sync-drivers')->dailyAt('02:45');
Schedule::command('tacho:fetch --all --since="-7 days"')->dailyAt('03:00');

// Housekeeping
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();
Schedule::command('tacho:prune-raw --days=90')->weekly();
