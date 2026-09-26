<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use VehicleData\Core\Contracts\Enricher;
use VehicleData\Core\Http\Resources\Enrichers;
use VehicleData\Core\Http\Resources\EnrichmentContext;
use VehicleData\Core\Models\Manufacturer;

it('lets a tagged enricher add class-3 keys and refuses changes to core keys', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'opel', 'name' => 'Opel']);
    app()->bind('test.enricher', fn () => new class implements Enricher
    {
        public function supports(string $resource): bool
        {
            return $resource === 'manufacturer';
        }

        public function enrich(Model $record, array $payload, EnrichmentContext $ctx): array
        {
            return $payload + ['headquarters' => 'Rüsselsheim'];
        }
    });
    app()->tag(['test.enricher'], 'core.enrichers');
    app()->forgetInstance(Enrichers::class);
    $this->getJson('/v1/manufacturers/opel', bearer($key))->assertOk()->assertJsonPath('data.headquarters', 'Rüsselsheim');

    app()->bind('test.bad', fn () => new class implements Enricher
    {
        public function supports(string $resource): bool
        {
            return true;
        }

        public function enrich(Model $record, array $payload, EnrichmentContext $ctx): array
        {
            $payload['name'] = 'Hacked';

            return $payload;
        }
    });
    app()->tag(['test.bad'], 'core.enrichers');
    app()->forgetInstance(Enrichers::class);
    $this->getJson('/v1/manufacturers/opel', bearer($key))->assertStatus(500);
});
