<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A single `schedule:run` cron entry drives everything below — the queue
// worker, the daily usage rollup/IP purge/prune, the daily cache gc, the
// daily dated import/status log prune, the weekly wikidata refresh and the
// weekly status report. The yearly sources (eea, ro-fleet) take a --year
// option and are run on demand, not scheduled.
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(2);
Schedule::command('vehicle:usage aggregate')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('vehicle:usage purge-ip')->dailyAt('00:20')->withoutOverlapping();
Schedule::command('vehicle:usage prune')->dailyAt('00:30')->withoutOverlapping();
Schedule::command('vehicle:cache gc')->dailyAt('00:40')->withoutOverlapping();
Schedule::command('vehicle:logs prune')->dailyAt('00:50')->withoutOverlapping();
Schedule::command('vehicle:import wikidata')->weeklyOn(0, '03:00')->withoutOverlapping(120)->appendOutputTo(storage_path('logs/imports-'.now()->toDateString().'.log'));
Schedule::command('vehicle:status')->weeklyOn(1, '07:00')->appendOutputTo(storage_path('logs/status-'.now()->toDateString().'.log'));
