<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;

// These assertions are only meaningful once the optimizer has a real
// population and fresh statistics to choose from - a handful of rows would
// let MariaDB pick a full scan and still "pass". ~300 rows are seeded per
// table, `ANALYZE TABLE` is run so the planner has current cardinality
// estimates, and the fixtures are built deterministically (fixed names,
// fixed cycling of filter values) so the EXPLAIN output never depends on
// factory randomness.

it('uses an index for the variant list filters', function (): void {
    $manufacturer = Manufacturer::factory()->create();
    $make = Make::factory()->create(['manufacturer_id' => $manufacturer->id]);
    $models = VehicleModel::factory()->count(10)->create(['make_id' => $make->id]);

    $fuels = ['petrol', 'diesel', 'electric'];
    $rows = [];
    $now = now();
    foreach ($models as $mi => $model) {
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [
                'public_id' => str_pad((string) (($mi * 30) + $i), 26, '0', STR_PAD_LEFT),
                'model_id' => $model->id,
                'fuel_code' => $fuels[$i % 3],
                'eu_category_code' => 'm1',
                'euro_norm_code' => 'euro_6d',
                'engine_cc' => 1000 + $i,
                'power_kw' => 50 + $i,
                'mass_kg' => 1200,
                'co2_wltp' => 120,
                'year_from' => 2020,
                'year_to' => null,
                'specifications' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }
    DB::table('vd_variants')->insert($rows);
    expect(DB::table('vd_variants')->count())->toBe(300);

    DB::statement('ANALYZE TABLE vd_variants');

    $targetModelId = $models->first()->id;
    $sql = Variant::query()->where('model_id', $targetModelId)->where('fuel_code', 'petrol')->orderBy('power_kw')->toRawSql();
    $rows = DB::select('EXPLAIN '.$sql);
    expect($rows[0]->key)->not->toBeNull();
});

it('uses an index for the make name prefix search', function (): void {
    $manufacturer = Manufacturer::factory()->create();
    $rows = [];
    $now = now();
    for ($i = 0; $i < 300; $i++) {
        $name = ($i % 10 === 0 ? 'Da' : 'Xx').sprintf('%04d', $i);
        $rows[] = [
            'public_id' => str_pad((string) $i, 26, '0', STR_PAD_LEFT),
            'slug' => 'make-'.$i,
            'name' => $name,
            'kind' => $i % 5 === 0 ? 'moped' : 'car',
            'manufacturer_id' => $manufacturer->id,
            'ro_fleet_count' => null,
            'ro_fleet_year' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::table('vd_makes')->insert($rows);
    expect(DB::table('vd_makes')->count())->toBe(300);

    DB::statement('ANALYZE TABLE vd_makes');

    $sql = Make::query()->where('kind', 'car')->where('name', 'like', 'Da%')->orderBy('name')->toRawSql();
    expect(DB::select('EXPLAIN '.$sql)[0]->key)->not->toBeNull();
});
