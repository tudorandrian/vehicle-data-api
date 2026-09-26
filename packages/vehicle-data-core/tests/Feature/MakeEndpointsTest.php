<?php

declare(strict_types=1);

use VehicleData\Core\Contracts\SpecificationSchema;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;

beforeEach(fn () => [, $this->key] = keyed());

it('lists car makes by default, filters by kind and manufacturer, and exposes ro_fleet only when present', function (): void {
    $renault = Manufacturer::factory()->create(['slug' => 'renault', 'name' => 'Renault']);
    Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia', 'manufacturer_id' => $renault->id, 'ro_fleet_count' => 1500000, 'ro_fleet_year' => 2025]);
    Make::factory()->create(['slug' => 'tesla', 'name' => 'Tesla', 'manufacturer_id' => null]);
    Make::factory()->create(['slug' => 'piaggio', 'name' => 'Piaggio', 'kind' => 'moped']);
    app(KindRegistry::class)->register(new class implements SpecificationSchema
    {
        public function kind(): string
        {
            return 'moped';
        }

        public function jsonSchema(): array
        {
            return ['type' => 'object', 'additionalProperties' => false];
        }

        public function openApiFragment(): array
        {
            return ['type' => 'object', 'title' => 'MopedSpecifications'];
        }
    });

    $res = $this->getJson('/v1/makes?sort=name', bearer($this->key))->assertOk()->assertJsonPath('meta.total', 2);
    expect($res->json('data.0'))->toMatchArray(['slug' => 'dacia', 'kind' => 'car', 'manufacturer' => 'renault', 'ro_fleet' => ['count' => 1500000, 'year' => 2025]])
        ->and($res->json('data.1'))->toMatchArray(['slug' => 'tesla', 'manufacturer' => null])->not->toHaveKey('ro_fleet');
    $this->getJson('/v1/makes?kind=moped', bearer($this->key))->assertJsonPath('meta.total', 1);
    $this->getJson('/v1/makes?manufacturer=renault', bearer($this->key))->assertJsonPath('data.0.slug', 'dacia')->assertJsonPath('meta.total', 1);
});

it('shows a make and its models', function (): void {
    $make = Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia']);
    $make->models()->create(['slug' => 'dacia-duster', 'name' => 'Duster', 'first_year' => 2010]);
    $make->models()->create(['slug' => 'dacia-spring', 'name' => 'Spring', 'first_year' => 2021]);
    $this->getJson('/v1/makes/dacia', bearer($this->key))->assertOk()->assertJsonPath('data.slug', 'dacia')->assertJsonStructure(['sources']);
    $this->getJson('/v1/makes/dacia/models?sort=-first_year', bearer($this->key))->assertOk()->assertJsonPath('data.0.slug', 'dacia-spring')->assertJsonPath('data.0.make', 'dacia');
    $this->getJson('/v1/makes/nope', bearer($this->key))->assertStatus(404);
});

it('rejects a kind that is not in the kind registry with a 422 problem', function (): void {
    Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia']);
    $this->getJson('/v1/makes?kind=foo', bearer($this->key))->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.0.field', 'kind');
    $this->getJson('/v1/makes?kind=car', bearer($this->key))->assertOk()->assertJsonPath('meta.total', 1);
});
