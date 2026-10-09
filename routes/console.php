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

// Every 2 hours: sync drivers, download the latest data (keeping 4+ weeks) and re-check the
// current week. Without schedule:run, call it from cron directly:
//   0 */2 * * * cd /path/to/app && php artisan tacho:refresh >> /dev/null 2>&1
Schedule::command('tacho:refresh')->everyTwoHours()->withoutOverlapping();

// Housekeeping
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();
Schedule::command('tacho:prune-raw --days=90')->weekly();
