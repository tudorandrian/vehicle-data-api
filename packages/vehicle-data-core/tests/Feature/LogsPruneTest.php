<?php

declare(strict_types=1);

it('deletes dated import and status logs older than LOG_DAILY_DAYS and keeps the rest', function (): void {
    $dir = sys_get_temp_dir().'/vd-logs-'.uniqid();
    mkdir($dir);
    config(['core.logs_path' => $dir, 'logging.channels.daily.days' => 14]);
    $old = now()->subDays(15)->toDateString();
    $new = now()->subDays(13)->toDateString();
    foreach (["imports-$old.log", "status-$old.log", "imports-$new.log", "laravel-$old.log"] as $f) {
        file_put_contents("$dir/$f", "x\n");
    }
    $this->artisan('vehicle:logs', ['action' => 'prune'])->expectsOutputToContain('Deleted 2 log file(s)')->assertExitCode(0);
    expect(array_map('basename', glob("$dir/*.log") ?: []))->toEqualCanonicalizing(["imports-$new.log", "laravel-$old.log"]);
});

it('keeps a dated log file exactly at the cutoff day and an undated legacy log', function (): void {
    $this->travelTo(now());
    $dir = sys_get_temp_dir().'/vd-logs-'.uniqid();
    mkdir($dir);
    config(['core.logs_path' => $dir, 'logging.channels.daily.days' => 14]);
    $boundary = now()->subDays(14)->toDateString();
    foreach (['imports-'.$boundary.'.log', 'imports.log', 'status.log'] as $f) {
        file_put_contents("$dir/$f", "x\n");
    }
    $this->artisan('vehicle:logs', ['action' => 'prune'])->expectsOutputToContain('Deleted 0 log file(s)')->assertExitCode(0);
    expect(array_map('basename', glob("$dir/*.log") ?: []))->toEqualCanonicalizing(['imports-'.$boundary.'.log', 'imports.log', 'status.log']);
});
