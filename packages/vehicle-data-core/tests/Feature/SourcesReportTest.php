<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\ExampleDataSeeder;

it('writes docs/data-sources.md with licences, retrieval, checksums and example coverage', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $out = sys_get_temp_dir().'/data-sources.md';
    $this->artisan('vehicle:sources', ['action' => 'report', '--out' => $out])->assertExitCode(0);
    $md = file_get_contents($out);
    expect($md)->toContain('## eea')->toContain('Source: European Environment Agency (EEA)')->toContain('OGL-ROU-1.0')->toContain('CC0-1.0')
        ->toContain('| fuel | petrol |')->toContain('example: none')->toContain('Trademark notice')->toContain('## Normalisation rules')
        ->toContain('## Known data issues')->toContain('no additional database right is claimed')->toContain('without warranty')
        ->toContain('rows read = distinct Wikidata items')->not->toContain('Fiat Fiat');
});

it('describes how each fixture is generated, sourced from scripts/fetch_fixtures.sh', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $out = sys_get_temp_dir().'/data-sources-fixtures.md';
    $this->artisan('vehicle:sources', ['action' => 'report', '--out' => $out])->assertExitCode(0);
    $md = file_get_contents($out);
    expect($md)->toContain('scripts/fetch_fixtures.sh')
        ->toContain('ROW_NUMBER() OVER (PARTITION BY Mk')
        ->toContain('AUTOTURISM')
        ->toContain('ORDER BY ?item')
        ->toContain('GetWMIsForManufacturer');
});

it('lists the registered sources', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $this->artisan('vehicle:sources', ['action' => 'list'])->assertExitCode(0);
});
