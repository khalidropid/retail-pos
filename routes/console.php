<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler. Nothing here runs unless the OS calls `php artisan
| schedule:run` every minute (cron on Linux, Task Scheduler on Windows);
| see README "النسخ الاحتياطي".
*/

// Heartbeat: lets the backup screen show whether the scheduler really runs.
Schedule::call(fn () => Cache::forever('scheduler.heartbeat', now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');

// Daily backup at the configured time (the command itself checks the enable switch).
Schedule::command('backup:run --scheduled')
    ->dailyAt((string) (rescue(fn () => settings('backup.schedule_time', '02:00'), '02:00', false) ?: '02:00'))
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled backup command failed'));
