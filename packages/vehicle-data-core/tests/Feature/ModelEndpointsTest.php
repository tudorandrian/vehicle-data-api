<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Variant;

beforeEach(function (): void {
    $this->seed(TaxonomySeeder::class);
    [, $this->key] = keyed();
});

it('shows a model with class-2 nulls and lists its variants with labels', function (): void {
    $make = Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia']);
    $model = $make->models()->create(['slug' => 'dacia-duster', 'name' => 'Duster', 'first_year' => null, 'last_year' => null]);
    Variant::factory()->create(['model_id' => $model->id, 'fuel_code' => 'petrol', 'eu_category_code' => 'm1', 'euro_norm_code' => 'euro_6d', 'power_kw' => 110]);
    Variant::factory()->create(['model_id' => $model->id, 'fuel_code' => 'diesel', 'eu_category_code' => 'm1', 'euro_norm_code' => null, 'power_kw' => 84, 'engine_cc' => 1461]);

    $show = $this->getJson('/v1/models/dacia-duster', bearer($this->key))->assertOk()->json('data');
    expect($show)->toMatchArray(['slug' => 'dacia-duster', 'make' => 'dacia', 'first_year' => null, 'last_year' => null])->not->toHaveKey('ro_fleet');

    // Accept-Language is explicit here (as in TaxonomyEndpointsTest) because the test
    // client's default Accept-Language is `en-us,en;q=0.5`, not the app's ro locale;
    // LabelResolver resolves from the request header first (see Locale\LabelResolver).
    $list = $this->getJson('/v1/models/dacia-duster/variants?sort=power_kw', bearer($this->key) + ['Accept-Language' => 'ro'])->assertOk()->assertJsonPath('meta.total', 2)->json('data');
    expect($list[0])->toMatchArray(['fuel' => ['code' => 'diesel', 'label' => 'Motorină'], 'euro_norm' => null, 'power_kw' => 84, 'power_hp' => 114])
        ->and($list[1]['euro_norm'])->toBe(['code' => 'euro_6d', 'label' => 'Euro 6d'])->and($list[1])->not->toHaveKey('specifications');
});
