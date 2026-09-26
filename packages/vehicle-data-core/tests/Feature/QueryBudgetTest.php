<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Make;

beforeEach(function (): void {
    $this->seed(TaxonomySeeder::class);
    Make::factory()->count(3)->create();
    [, $this->key] = keyed();
    $this->getJson('/v1/makes', bearer($this->key))->assertOk(); // warm the client cache
});

it('serves a list route within the query budget', function (string $path): void {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson($path, bearer($this->key))->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    // Includes the authoritative credential check on a metadata-cache hit (ADR 0008).
    expect(count($queries))->toBeLessThanOrEqual(9, implode("\n", $queries));
})->with(['/v1/makes', '/v1/taxonomies/fuel']);
