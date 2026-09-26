<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\Variant;

const EEA_FIXTURE = 'packages/vehicle-data-core/database/fixtures/eea_ro_2024.json';

beforeEach(fn () => $this->seed(TaxonomySeeder::class));

it('imports the fixture with provenance and normalised names', function (): void {
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('succeeded')->and($run->rows_read)->toBeGreaterThanOrEqual(150)->and($run->rows_rejected)->toBeLessThan($run->rows_read * 0.05)
        ->and($run->file_checksum)->toHaveLength(64);
    expect(Make::query()->where('slug', 'dacia')->value('name'))->toBe('Dacia')
        ->and(Make::query()->where('slug', 'mercedes-benz')->value('name'))->toBe('Mercedes-Benz')
        ->and(Variant::query()->distinct()->pluck('fuel_code')->all())->toContain('petrol', 'diesel', 'electric')
        ->and(RecordSource::query()->where('record_type', 'variant')->count())->toBe(Variant::count());
    $v = Variant::query()->whereHas('model', fn ($q) => $q->where('slug', 'dacia-duster'))->where('fuel_code', 'petrol')->firstOrFail();
    expect($v->eu_category_code)->toBe('m1')->and($v->year_from)->toBe(2024)->and($v->specifications)->toHaveKeys(['euro_stage_raw', 'fuel_mode', 'type_approval_number']);
});

it('is idempotent and keeps public ids stable', function (): void {
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    $ids = Variant::query()->orderBy('id')->pluck('public_id')->all();
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    expect(Variant::query()->orderBy('id')->pluck('public_id')->all())->toBe($ids)->and(ImportRun::count())->toBe(2);
});

it('widens the year range on re-import instead of overwriting it', function (): void {
    $rows = json_decode((string) file_get_contents(base_path(EEA_FIXTURE)), true)['results'];
    $row = $rows[0];
    $row['Year'] = 2024;
    $tmp2024 = tempnam(sys_get_temp_dir(), 'eea').'.json';
    file_put_contents($tmp2024, json_encode(['results' => [$row]]));
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => $tmp2024])->assertExitCode(0);

    $row['Year'] = 2022;
    $tmp2022 = tempnam(sys_get_temp_dir(), 'eea').'.json';
    file_put_contents($tmp2022, json_encode(['results' => [$row]]));
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => $tmp2022])->assertExitCode(0);

    expect(Variant::count())->toBe(1);
    $variant = Variant::query()->firstOrFail();
    expect($variant->year_from)->toBe(2022);
});

it('advances retrieved_at on re-import for both a make and a model', function (): void {
    // CatalogueIdentity::provenance() used to firstOrCreate() the identity-resolution row, so
    // retrieved_at froze at whatever the first import wrote — a re-import from the same source
    // never advanced it, unlike ImportPipeline's own updateOrCreate() write for the record itself.
    $this->travelTo('2026-09-18T10:00:00Z');
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    [, $key] = keyed();
    $makeFirst = $this->getJson('/v1/makes/dacia', bearer($key))->assertOk()->json('sources.0.retrieved_at');
    $modelFirst = $this->getJson('/v1/models/dacia-duster', bearer($key))->assertOk()->json('sources.0.retrieved_at');

    $this->travelTo('2026-09-18T13:08:41Z');
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    $makeSecond = $this->getJson('/v1/makes/dacia', bearer($key))->assertOk()->json('sources.0.retrieved_at');
    $modelSecond = $this->getJson('/v1/models/dacia-duster', bearer($key))->assertOk()->json('sources.0.retrieved_at');

    expect(CarbonImmutable::parse($makeSecond)->greaterThan(CarbonImmutable::parse($makeFirst)))->toBeTrue()
        ->and(CarbonImmutable::parse($modelSecond)->greaterThan(CarbonImmutable::parse($modelFirst)))->toBeTrue();
});

it('rejects rows with an unknown fuel or category and reports the rule', function (): void {
    $rows = json_decode((string) file_get_contents(base_path(EEA_FIXTURE)), true)['results'];
    $rows[0]['Ft'] = 'coal';
    $rows[1]['Ct'] = 'T1';
    $tmp = tempnam(sys_get_temp_dir(), 'eea').'.json';
    file_put_contents($tmp, json_encode(['results' => $rows]));
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => $tmp])->assertExitCode(0);
    $report = ImportRun::query()->latest('id')->firstOrFail()->reject_report;
    expect($report['summary'])->toMatchArray(['fuel' => 1, 'eu_category' => 1]);
});

it('keeps make and model ids across an import whose normalised names changed', function (): void {
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    $dacia = Make::query()->where('slug', 'dacia')->firstOrFail();
    $dacia->forceFill(['name' => 'Dacia Old', 'slug' => 'dacia-old'])->save(); // simulate a previous normaliser output
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path(EEA_FIXTURE)])->assertExitCode(0);
    expect(Make::query()->where('slug', 'dacia')->value('public_id'))->toBe($dacia->public_id)
        ->and(Make::query()->where('slug', 'dacia-old')->exists())->toBeFalse()
        ->and(SlugAlias::query()->where('slug', 'dacia-old')->value('record_id'))->toBe($dacia->id)
        ->and(Variant::query()->whereHas('model', fn ($q) => $q->where('make_id', $dacia->id))->count())->toBeGreaterThan(0);
});
