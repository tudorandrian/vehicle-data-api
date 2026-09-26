<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\Variant;

it('streams semicolon-delimited UTF-8 CSV with a BOM on list routes', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'skoda', 'name' => 'Škoda', 'country_code' => 'CZ']);
    $res = $this->get('/v1/manufacturers?format=csv', bearer($key));
    $res->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $res->streamedContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")->and($body)->toContain('id;slug;name;')->toContain('skoda;Škoda;CZ');
    $this->get('/v1/manufacturers', bearer($key) + ['Accept' => 'text/csv'])->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('answers 406 for unsupported representations and for CSV on detail routes', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'fiat', 'name' => 'Fiat']);
    $this->get('/v1/manufacturers', bearer($key) + ['Accept' => 'application/xml'])->assertStatus(406)->assertHeader('Content-Type', 'application/problem+json');
    $this->get('/v1/manufacturers/fiat?format=csv', bearer($key))->assertStatus(406);
});

it('escapes cells that could be read as spreadsheet formulas', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'injection', 'name' => '=SUM(A1:A9)', 'website' => '+1234']);
    $res = $this->get('/v1/manufacturers?format=csv', bearer($key));
    $body = $res->streamedContent();
    expect($body)->toContain("'=SUM(A1:A9)")->toContain("'+1234")->not->toContain(';=SUM(A1:A9);')->not->toContain(';+1234');
});

it('keeps the CSV header stable and every row the same width when only some rows have class-3 keys', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'aaa', 'name' => 'AAA', 'logo_commons_file' => null]);
    Manufacturer::factory()->create(['slug' => 'bbb', 'name' => 'BBB', 'logo_commons_file' => 'Bbb logo.svg', 'logo_licence' => 'Public domain']);

    $res = $this->get('/v1/manufacturers?format=csv&sort=name', bearer($key));
    $lines = csvLines($res->streamedContent());

    $header = str_getcsv($lines[0], ';');
    expect($header)->toBe(['id', 'slug', 'name', 'country_code', 'founded_year', 'parent', 'website', 'logo.commons_file', 'logo.licence', 'logo.url'])
        ->and($lines)->toHaveCount(3);

    foreach ($lines as $line) {
        expect(str_getcsv($line, ';'))->toHaveCount(count($header));
    }

    $bbbRow = str_getcsv($lines[2], ';');
    expect($bbbRow[array_search('slug', $header, true)])->toBe('bbb')
        ->and($bbbRow[array_search('logo.commons_file', $header, true)])->toBe('Bbb logo.svg')
        ->and($bbbRow[array_search('logo.licence', $header, true)])->toBe('Public domain');

    $aaaRow = str_getcsv($lines[1], ';');
    expect($aaaRow[array_search('logo.commons_file', $header, true)])->toBe('');
});

it('respects sparse fieldsets in the CSV header', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'ccc', 'name' => 'CCC', 'website' => 'https://ccc.example']);

    $res = $this->get('/v1/manufacturers?format=csv&fields=website', bearer($key));
    $lines = csvLines($res->streamedContent());

    expect(str_getcsv($lines[0], ';'))->toBe(['id', 'slug', 'name', 'website'])
        ->and($lines)->toHaveCount(2);
});

it('fills every class-1 cell, including flattened term objects, on the variants CSV', function (): void {
    $this->seed(TaxonomySeeder::class);
    [, $key] = keyed();
    $make = Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia']);
    $model = $make->models()->create(['slug' => 'dacia-duster', 'name' => 'Duster']);
    Variant::factory()->create(['model_id' => $model->id, 'public_id' => str_repeat('9', 26), 'specifications' => ['doors' => 5, 'note' => 'ș']]);

    $res = $this->get('/v1/models/dacia-duster/variants?format=csv', bearer($key));
    $lines = csvLines($res->streamedContent());
    $header = str_getcsv($lines[0], ';');
    expect($header)->toBe([
        'id', 'make', 'model', 'fuel.code', 'fuel.label', 'eu_category.code', 'eu_category.label',
        'euro_norm.code', 'euro_norm.label', 'engine_cc', 'power_kw', 'power_hp', 'mass_kg', 'co2_wltp', 'year_from', 'year_to', 'specifications',
    ]);
    $row = str_getcsv($lines[1], ';');

    foreach (['id', 'make', 'model', 'fuel.code', 'fuel.label', 'eu_category.code', 'eu_category.label'] as $column) {
        expect($row[array_search($column, $header, true)])->not->toBe('', "column {$column} is empty");
    }
    expect($row[array_search('specifications', $header, true)])->toBe('{"doors":5,"note":"ș"}');
});

it('fills class-3 cells on manufacturers, makes and models CSVs', function (): void {
    [, $key] = keyed();
    $man = Manufacturer::factory()->create(['slug' => 'renault', 'name' => 'Renault']);
    $make = Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia', 'manufacturer_id' => $man->id, 'ro_fleet_count' => 12, 'ro_fleet_year' => 2025]);
    $make->models()->create(['slug' => 'dacia-duster', 'name' => 'Duster', 'ro_fleet_count' => 3, 'ro_fleet_year' => 2025]);

    $makesLines = csvLines($this->get('/v1/makes?format=csv', bearer($key))->streamedContent());
    $makesHeader = str_getcsv($makesLines[0], ';');
    $makesRow = str_getcsv($makesLines[1], ';');
    expect($makesRow[array_search('ro_fleet.count', $makesHeader, true)])->toBe('12')
        ->and($makesRow[array_search('ro_fleet.year', $makesHeader, true)])->toBe('2025');

    $modelsLines = csvLines($this->get('/v1/makes/dacia/models?format=csv', bearer($key))->streamedContent());
    $modelsHeader = str_getcsv($modelsLines[0], ';');
    $modelsRow = str_getcsv($modelsLines[1], ';');
    expect($modelsRow[array_search('ro_fleet.count', $modelsHeader, true)])->toBe('3')
        ->and($modelsRow[array_search('ro_fleet.year', $modelsHeader, true)])->toBe('2025');
});

/** @return list<string> */
function csvLines(string $streamed): array
{
    $withoutBom = str_starts_with($streamed, "\xEF\xBB\xBF") ? substr($streamed, 3) : $streamed;
    $lines = preg_split('/\r\n|\n/', rtrim($withoutBom)) ?: [];

    return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
}
