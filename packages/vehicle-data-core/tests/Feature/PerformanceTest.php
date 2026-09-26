<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\ExampleDataSeeder;
use VehicleData\Core\Usage\Percentile;

it('serves the list routes under 150 ms at p95 on the seeded database', function (string $path): void {
    $this->seed(ExampleDataSeeder::class);
    [, $key] = keyed();
    $times = [];
    for ($i = 0; $i < 20; $i++) {
        $t = hrtime(true);
        $this->getJson($path, bearer($key))->assertOk();
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    $p95 = Percentile::p95($times);
    expect($p95)->toBeLessThan(150.0, 'p95='.round($p95, 2).'ms for '.$path);
})->with(['/v1/makes/dacia/models', '/v1/models/dacia-duster/variants?fuel=petrol', '/v1/makes?sort=-ro_fleet_count']);
