<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyTerm;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Taxonomies\TaxonomyIdentity;

beforeEach(fn () => $this->seed(TaxonomySeeder::class));

it('keeps the taxonomy public id and reserves its prior name during a rename', function (): void {
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    $id = $fuel->public_id;
    $renamed = TaxonomyIdentity::rename($fuel, 'energy');

    expect($renamed->public_id)->toBe($id)->and($renamed->name)->toBe('energy');
    expect(fn () => TaxonomyIdentity::rename(Taxonomy::query()->where('name', 'gearbox')->firstOrFail(), 'fuel'))
        ->toThrow(LogicException::class, 'reserved');
});

it('keeps term identity and changes catalogue references atomically on a code rename', function (): void {
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    $diesel = TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->firstOrFail();
    $variant = Variant::factory()->create(['fuel_code' => 'diesel']);
    $id = $diesel->public_id;
    $renamed = TaxonomyIdentity::renameTerm($diesel, 'combustion_diesel');

    expect($renamed->public_id)->toBe($id)->and($renamed->code)->toBe('combustion_diesel');
    expect($variant->refresh()->fuel_code)->toBe('combustion_diesel');
    expect(fn () => TaxonomyIdentity::renameTerm(TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'petrol')->firstOrFail(), 'diesel'))
        ->toThrow(LogicException::class, 'reserved');
});

it('rejects invalid rename keys before making a change', function (): void {
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    $diesel = TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->firstOrFail();

    expect(fn () => TaxonomyIdentity::rename($fuel, 'Fuel'))->toThrow(InvalidArgumentException::class);
    expect(fn () => TaxonomyIdentity::renameTerm($diesel, 'diesel fuel'))->toThrow(InvalidArgumentException::class);
});
