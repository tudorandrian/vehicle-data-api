<?php

declare(strict_types=1);

use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\PublicIdFormat;

it('assigns an immutable public_id to manufacturers, makes and models at creation', function (): void {
    $m = Manufacturer::factory()->create();
    $mk = Make::factory()->create(['manufacturer_id' => $m->id]);
    $md = VehicleModel::factory()->create(['make_id' => $mk->id]);
    foreach ([$m, $mk, $md] as $record) {
        expect($record->public_id)->toMatch(PublicIdFormat::PATTERN);
    }
    $before = $mk->public_id;
    $mk->forceFill(['name' => 'Renamed', 'slug' => 'renamed'])->save();
    expect($mk->fresh()->public_id)->toBe($before);
});

it('keeps an explicitly given public_id', function (): void {
    $mk = Make::factory()->create(['public_id' => '01J8ZQ3W7S5K4M2N9P6R8T1V0X']);
    expect($mk->fresh()->public_id)->toBe('01J8ZQ3W7S5K4M2N9P6R8T1V0X');
});

it('stores slug aliases per record', function (): void {
    $mk = Make::factory()->create(['slug' => 'volkswagen']);
    SlugAlias::query()->create(['record_type' => 'make', 'record_id' => $mk->id, 'slug' => 'vw']);
    expect(SlugAlias::query()->where('slug', 'vw')->value('record_id'))->toBe($mk->id);
});

it('resolves VehicleModelFactory make_id lazily (no orphan makes) while an explicit slug still wins', function (): void {
    $mk = Make::factory()->create(['slug' => 'volkswagen', 'name' => 'Volkswagen']);

    VehicleModel::factory()->count(3)->create(['make_id' => $mk->id]);
    expect(Make::count())->toBe(1);

    $withSlug = VehicleModel::factory()->create(['make_id' => $mk->id, 'slug' => 'volkswagen-golf']);
    expect(Make::count())->toBe(1)
        ->and($withSlug->slug)->toBe('volkswagen-golf');
});
