<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('deletes expired rows from the database cache table and keeps live ones', function (): void {
    DB::table('cache')->insert([
        ['key' => 'dead', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->timestamp],
        ['key' => 'live', 'value' => 's:1:"x";', 'expiration' => now()->addHour()->timestamp],
    ]);
    $this->artisan('vehicle:cache', ['action' => 'gc'])->expectsOutputToContain('Removed 1 expired cache row(s)')->assertExitCode(0);
    expect(DB::table('cache')->pluck('key')->all())->toBe(['live']);
});

it('keeps a cache row that expires exactly now', function (): void {
    $this->travelTo(now());
    DB::table('cache')->insert(['key' => 'boundary', 'value' => 's:1:"x";', 'expiration' => now()->timestamp]);
    $this->artisan('vehicle:cache', ['action' => 'gc'])->expectsOutputToContain('Removed 0 expired cache row(s)')->assertExitCode(0);
    expect(DB::table('cache')->pluck('key')->all())->toBe(['boundary']);
});

it('rejects an unknown vehicle:cache action', function (): void {
    $this->artisan('vehicle:cache', ['action' => 'bogus'])->expectsOutputToContain('Unknown action; expected "gc".')->assertExitCode(2);
});
