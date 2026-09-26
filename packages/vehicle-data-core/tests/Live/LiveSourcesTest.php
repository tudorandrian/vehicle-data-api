<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\ImportRun;

beforeEach(function (): void {
    $this->seed(TaxonomySeeder::class);
    // wikidata/wmi need makes to link to; the committed EEA fixture (not a live download)
    // seeds them cheaply before the live imports run against the real endpoints.
    $this->artisan('vehicle:import', [
        'source' => 'eea', '--file' => base_path('packages/vehicle-data-core/database/fixtures/eea_ro_2024.json'),
    ])->assertExitCode(0);
});

it('runs one real download per source with --limit=500', function (string $source, array $args): void {
    $this->artisan('vehicle:import', ['source' => $source, '--limit' => 500] + $args)->assertExitCode(0);
    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('succeeded')
        ->and($run->rows_read)->toBeGreaterThan(0)
        ->and($run->rows_written)->toBeGreaterThan(0);
})->with([['eea', ['--year' => 2024]], ['ro-fleet', ['--year' => 2025]], ['wikidata', []], ['wmi', []]])->group('network');
