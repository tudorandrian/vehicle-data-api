<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VehicleData\Core\Models\ApiUsageDaily;
use VehicleData\Core\Usage\AggregateDailyUsage;
use VehicleData\Core\Usage\Percentile;

it('computes p95 in the database and agrees with Percentile::p95 on 1000 rows, one row and no rows', function (): void {
    [$client] = keyed();
    $day = CarbonImmutable::parse('2026-03-01 12:00:00');
    $durations = [];
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $d = ($i * 37) % 991; // spread, not sorted
        $durations[] = $d;
        $rows[] = [
            'request_id' => (string) Str::uuid(),
            'client_id' => $client->id,
            'route' => 'v1.makes.index',
            'method' => 'GET',
            'status' => 200,
            'duration_ms' => $d,
            'bytes' => 0,
            'created_at' => $day,
        ];
    }
    DB::table('vd_api_requests')->insert($rows);
    [$one] = keyed();
    DB::table('vd_api_requests')->insert([[
        'request_id' => (string) Str::uuid(),
        'client_id' => $one->id,
        'route' => 'v1.makes.index',
        'method' => 'GET',
        'status' => 200,
        'duration_ms' => 123,
        'bytes' => 0,
        'created_at' => $day,
    ]]);

    expect(AggregateDailyUsage::forDate($day))->toBe(2);
    expect(ApiUsageDaily::query()->where('client_id', $client->id)->value('p95_ms'))->toBe((int) Percentile::p95($durations));
    expect(ApiUsageDaily::query()->where('client_id', $one->id)->value('p95_ms'))->toBe(123);
    expect(AggregateDailyUsage::forDate($day->addDays(3)))->toBe(0);
});
