<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use VehicleData\Core\Models\ApiRequest;
use VehicleData\Core\Models\ApiUsageDaily;
use VehicleData\Core\Usage\AggregateDailyUsage;
use VehicleData\Core\Usage\PurgeRequestIps;

it('aggregates requests, errors and p95 per client per day, then purges old ips', function (): void {
    [$client] = keyed();
    $day = CarbonImmutable::parse('2026-09-15');
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 500] as $i => $ms) {
        ApiRequest::query()->create(['request_id' => (string) Str::uuid(), 'client_id' => $client->id, 'route' => 'v1.makes.index', 'method' => 'GET', 'status' => $i === 9 ? 500 : 200, 'duration_ms' => $ms, 'bytes' => 100, 'ip' => '10.0.0.1', 'created_at' => $day->addMinutes($i)]);
    }
    expect(AggregateDailyUsage::forDate($day))->toBe(1);
    $row = ApiUsageDaily::query()->where('client_id', $client->id)->whereDate('date', $day)->firstOrFail();
    expect($row->requests)->toBe(10)->and($row->errors)->toBe(1)->and($row->p95_ms)->toBe(500);
    AggregateDailyUsage::forDate($day);
    expect(ApiUsageDaily::count())->toBe(1);
    $this->travelTo($day->addDays(31));
    expect(PurgeRequestIps::run())->toBe(10)->and(ApiRequest::query()->whereNotNull('ip')->count())->toBe(0);
});

it('aggregates anonymous (client_id null) requests into exactly one row, idempotently', function (): void {
    $day = CarbonImmutable::parse('2026-09-15');
    foreach ([5, 15] as $i => $ms) {
        ApiRequest::query()->create(['request_id' => (string) Str::uuid(), 'client_id' => null, 'route' => 'v1.health', 'method' => 'GET', 'status' => 200, 'duration_ms' => $ms, 'bytes' => 50, 'ip' => null, 'created_at' => $day->addMinutes($i)]);
    }

    expect(AggregateDailyUsage::forDate($day))->toBe(1);
    expect(ApiUsageDaily::query()->whereNull('client_id')->whereDate('date', $day)->count())->toBe(1);

    // Re-running the same date must upsert the existing null-client row, not
    // insert a second one — `client_id` is `null`, so the `updateOrCreate`
    // match condition has to use `whereNull`, not `where('client_id', null)`
    // (which never matches in SQL).
    expect(AggregateDailyUsage::forDate($day))->toBe(1);
    expect(ApiUsageDaily::query()->whereNull('client_id')->whereDate('date', $day)->count())->toBe(1);

    $row = ApiUsageDaily::query()->whereNull('client_id')->whereDate('date', $day)->firstOrFail();
    expect($row->requests)->toBe(2);
});
