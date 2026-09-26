<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use VehicleData\Core\Models\ApiRequest;
use VehicleData\Core\Usage\PruneRequests;

it('deletes request rows older than the retention window in chunks and keeps newer ones', function (): void {
    config(['core.request_retention_days' => 90]);
    [$client] = keyed();
    $old = ['request_id' => fake()->uuid(), 'client_id' => $client->id, 'route' => 'v1.makes.index', 'method' => 'GET', 'status' => 200, 'duration_ms' => 5, 'bytes' => 10, 'ip' => null];
    foreach (range(1, 12) as $i) {
        ApiRequest::query()->create($old + ['created_at' => now()->subDays(91)]);
    }
    ApiRequest::query()->create($old + ['created_at' => now()->subDays(89)]);
    expect(PruneRequests::run(5))->toBe(12)->and(ApiRequest::count())->toBe(1);
});

it('is exposed as vehicle:usage prune', function (): void {
    $this->artisan('vehicle:usage', ['action' => 'prune'])->expectsOutputToContain('Pruned 0 request(s)')->assertExitCode(0);
});

it('keeps a request row exactly at the retention cutoff', function (): void {
    $this->travelTo(now());
    config(['core.request_retention_days' => 90]);
    [$client] = keyed();
    $row = ['request_id' => fake()->uuid(), 'client_id' => $client->id, 'route' => 'v1.makes.index', 'method' => 'GET', 'status' => 200, 'duration_ms' => 5, 'bytes' => 10, 'ip' => null, 'created_at' => now()->subDays(90)];
    ApiRequest::query()->create($row);
    expect(PruneRequests::run())->toBe(0)->and(ApiRequest::count())->toBe(1);
});

it('deletes expired counter rows and keeps unexpired ones', function (): void {
    DB::table('vd_api_counters')->insert([
        ['scope' => 'client:1', 'window' => 'm:expired', 'count' => 1, 'expires_at' => now()->subDay()],
        ['scope' => 'client:1', 'window' => 'm:fresh', 'count' => 1, 'expires_at' => now()->addDay()],
    ]);
    PruneRequests::run();
    expect(DB::table('vd_api_counters')->pluck('window')->all())->toBe(['m:fresh']);
});
