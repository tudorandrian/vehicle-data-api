<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyTerm;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Taxonomies\TaxonomyIdentity;

beforeEach(function (): void {
    $this->seed(TaxonomySeeder::class);
    [, $this->key] = keyed();
    $model = Make::factory()->create(['slug' => 'vw', 'name' => 'Volkswagen'])->models()->create(['slug' => 'vw-golf', 'name' => 'Golf']);
    Variant::factory()->create(['model_id' => $model->id, 'public_id' => str_repeat('A', 26), 'fuel_code' => 'petrol', 'engine_cc' => 999, 'power_kw' => 81, 'year_from' => 2020, 'year_to' => 2023, 'specifications' => ['doors' => 5]]);
    Variant::factory()->create(['model_id' => $model->id, 'public_id' => str_repeat('B', 26), 'fuel_code' => 'diesel', 'engine_cc' => 1968, 'power_kw' => 110, 'euro_norm_code' => 'euro_6e', 'year_from' => 2024, 'year_to' => null]);
    Variant::factory()->create(['model_id' => $model->id, 'public_id' => str_repeat('C', 26), 'fuel_code' => 'electric', 'engine_cc' => null, 'power_kw' => 150, 'eu_category_code' => 'n1', 'year_from' => 2024]);
});

it('filters by fuel, category, euro norm, year and ranges', function (): void {
    $u = fn (string $qs) => $this->getJson('/v1/models/vw-golf/variants?'.$qs, bearer($this->key))->assertOk()->json('meta.total');
    expect($u('fuel=diesel'))->toBe(1)->and($u('eu_category=n1'))->toBe(1)->and($u('euro_norm=euro_6e'))->toBe(1)
        ->and($u('year=2022'))->toBe(1)->and($u('year=2025'))->toBe(2)
        ->and($u('power_kw_min=100&power_kw_max=120'))->toBe(1)->and($u('engine_cc_min=1500'))->toBe(1);
});

it('applies a zero range bound instead of ignoring it', function (): void {
    $u = fn (string $qs) => $this->getJson('/v1/models/vw-golf/variants?'.$qs, bearer($this->key))->assertOk()->json('meta.total');
    expect($u('power_kw_max=0'))->toBe(0)->and($u('engine_cc_max=0'))->toBe(0)
        ->and($u('power_kw_min=0'))->toBe(3)->and($u('engine_cc_min=0'))->toBe(2);
});

it('accepts a retired taxonomy term code in a filter during client migration', function (): void {
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    $diesel = TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->firstOrFail();
    TaxonomyIdentity::renameTerm($diesel, 'combustion_diesel');

    $this->getJson('/v1/models/vw-golf/variants?fuel=diesel', bearer($this->key))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.fuel.code', 'combustion_diesel');
});

it('shows one variant with sources and specifications', function (): void {
    $json = $this->getJson('/v1/variants/'.str_repeat('A', 26), bearer($this->key))->assertOk()->assertJsonStructure(['sources'])->json('data');
    expect($json)->toMatchArray(['id' => str_repeat('A', 26), 'make' => 'vw', 'model' => 'vw-golf', 'engine_cc' => 999, 'power_hp' => 110, 'specifications' => ['doors' => 5]]);
    $this->getJson('/v1/variants/'.str_repeat('Z', 26), bearer($this->key))->assertStatus(404);
});

it('rejects unknown filter values with 422', function (): void {
    $this->getJson('/v1/models/vw-golf/variants?fuel=coal', bearer($this->key))->assertStatus(422)->assertJsonPath('errors.0.field', 'fuel');
});
