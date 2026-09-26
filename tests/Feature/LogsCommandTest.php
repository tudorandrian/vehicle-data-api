<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    // A disposable directory per test, never the real storage/logs: the previous version of
    // this test wrote and deleted files there directly, racing any other process reading or
    // rotating the real application log at the same time.
    $this->logsDir = sys_get_temp_dir().'/vd-logs-test-'.bin2hex(random_bytes(6));
    mkdir($this->logsDir);
    config(['core.logs_path' => $this->logsDir]);
});

afterEach(function (): void {
    foreach (glob($this->logsDir.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->logsDir);
});

it('tails JSON logs filtered by status class and age', function (): void {
    $file = $this->logsDir.'/laravel-'.now()->toDateString().'.log';
    $old = json_encode(['message' => 'api.request', 'context' => ['status' => 500, 'route' => 'x'], 'datetime' => now()->subHours(3)->toIso8601String()]);
    $new = json_encode(['message' => 'api.request', 'context' => ['status' => 503, 'route' => 'y'], 'datetime' => now()->toIso8601String()]);
    $ok = json_encode(['message' => 'api.request', 'context' => ['status' => 200, 'route' => 'z'], 'datetime' => now()->toIso8601String()]);
    file_put_contents($file, implode("\n", [$old, $new, $ok])."\n", FILE_APPEND);
    $this->artisan('vehicle:logs', ['action' => 'tail', '--status' => '5xx', '--since' => '1h'])->expectsOutputToContain('"route":"y"')->doesntExpectOutputToContain('"route":"x"')->doesntExpectOutputToContain('"route":"z"')->assertExitCode(0);
});

it('returns the newest lines in chronological order when --since spans two daily log files', function (): void {
    $this->travelTo(Carbon::parse('2026-01-02 00:30:00'));

    $yesterday = $this->logsDir.'/laravel-2026-01-01.log';
    $today = $this->logsDir.'/laravel-2026-01-02.log';

    // Three matching lines in yesterday's file alone - more than --lines=2 -
    // so the buggy (files newest-first, then array_slice the concatenated
    // list) behaviour would return old2/old3 and miss today's new1 entirely.
    $lines = [
        ['route' => 'old1', 'at' => '2026-01-01T23:00:00+00:00'],
        ['route' => 'old2', 'at' => '2026-01-01T23:10:00+00:00'],
        ['route' => 'old3', 'at' => '2026-01-01T23:20:00+00:00'],
    ];
    file_put_contents(
        $yesterday,
        implode("\n", array_map(fn (array $l) => json_encode(['message' => 'api.request', 'context' => ['status' => 200, 'route' => $l['route']], 'datetime' => $l['at']]), $lines))."\n"
    );
    file_put_contents(
        $today,
        json_encode(['message' => 'api.request', 'context' => ['status' => 200, 'route' => 'new1'], 'datetime' => '2026-01-02T00:15:00+00:00'])."\n"
    );

    Artisan::call('vehicle:logs', ['action' => 'tail', '--since' => '3h', '--lines' => 2]);
    $output = Artisan::output();

    expect($output)->toContain('"route":"old3"')->toContain('"route":"new1"')
        ->not->toContain('"route":"old1"')->not->toContain('"route":"old2"');
    // Chronological, not just present: old3 (23:20) prints before new1 (00:15 next day).
    expect(strpos($output, 'old3'))->toBeLessThan(strpos($output, 'new1'));
});
