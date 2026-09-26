<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\VehicleModel;

const FLEET_FIXTURE = 'packages/vehicle-data-core/database/fixtures/ro_fleet_2025.csv';

beforeEach(fn () => $this->seed(TaxonomySeeder::class));

it('aggregates counties into make and model fleet counts with the reference year', function (): void {
    $this->artisan('vehicle:import', ['source' => 'ro-fleet', '--year' => 2025, '--file' => base_path(FLEET_FIXTURE)])->assertExitCode(0);
    $dacia = Make::query()->where('slug', 'dacia')->firstOrFail();
    expect($dacia->ro_fleet_year)->toBe(2025)->and($dacia->ro_fleet_count)->toBeGreaterThan(0)->and($dacia->kind)->toBe('car');
    $duster = VehicleModel::query()->where('slug', 'dacia-duster')->firstOrFail();
    expect($duster->ro_fleet_count)->toBeGreaterThan(0)->and($duster->ro_fleet_count)->toBeLessThanOrEqual($dacia->ro_fleet_count)
        // One row-level provenance row (ImportPipeline) plus one raw-value provenance row (CatalogueIdentity).
        ->and(RecordSource::query()->where('record_type', 'model')->where('record_id', $duster->id)->where('source_ref', '2025|DACIA|DUSTER')->exists())->toBeTrue();
    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('succeeded')->and($run->file_checksum)->toHaveLength(64);
});

it('attaches counts to models already imported from the EEA and is idempotent', function (): void {
    $this->artisan('vehicle:import', ['source' => 'eea', '--year' => 2024, '--file' => base_path('packages/vehicle-data-core/database/fixtures/eea_ro_2024.json')])->assertExitCode(0);
    $before = VehicleModel::count();
    $this->artisan('vehicle:import', ['source' => 'ro-fleet', '--year' => 2025, '--file' => base_path(FLEET_FIXTURE)])->assertExitCode(0);
    $duster = VehicleModel::query()->where('slug', 'dacia-duster')->firstOrFail();
    expect($duster->ro_fleet_count)->toBeGreaterThan(0)->and($duster->variants()->count())->toBeGreaterThan(0);
    $count = $duster->ro_fleet_count;
    $this->artisan('vehicle:import', ['source' => 'ro-fleet', '--year' => 2025, '--file' => base_path(FLEET_FIXTURE)])->assertExitCode(0);
    expect($duster->fresh()->ro_fleet_count)->toBe($count)->and(VehicleModel::count())->toBeGreaterThanOrEqual($before);
});

it('exposes ro_fleet through the API only where present', function (): void {
    $this->artisan('vehicle:import', ['source' => 'ro-fleet', '--year' => 2025, '--file' => base_path(FLEET_FIXTURE)])->assertExitCode(0);
    Make::factory()->create(['slug' => 'zzz', 'name' => 'Zzz']);
    [, $key] = keyed();
    $this->getJson('/v1/makes/dacia', bearer($key))->assertOk()->assertJsonPath('data.ro_fleet.year', 2025);
    expect($this->getJson('/v1/makes/zzz', bearer($key))->json('data'))->not->toHaveKey('ro_fleet');
    $source = Source::query()->where('key', 'ro-fleet')->firstOrFail();
    expect($source->attribution)->toBe('Contains public information under the Open Government Licence v1.0');
});

it('sums two raw make spellings that normalise to the same slug instead of one overwriting the other, and counts the rejects per distinct raw pair', function (): void {
    // "MERCEDES BENZ" and "MERCEDES-BENZ" both normalise to the same "Mercedes-Benz" / "mercedes-benz"
    // slug (NameNormaliser aliasing). A CSV that spells the make both ways across two counties must sum
    // into one fleet count on the Make record, not have the second raw spelling's write clobber the
    // first's. "GTZ6119BEVBF" is a real DRPCIV commercial-name value that NameNormaliser::model() rejects
    // as a type code; it appears under both raw make spellings so the reject is counted twice (once per
    // distinct raw (make, model) pair), not once per underlying CSV row.
    $csv = <<<'CSV'
     ;JUDET;CATEGORIE_NATIONALA;CATEGORIE_COMUNITARA;MARCA;DESCRIERE_COMERCIALA;TOTAL_VEHICULE
    1;ALBA;AUTOTURISM;M1     ;MERCEDES BENZ;CLA;10
    2;BIHOR;AUTOTURISM;M1     ;MERCEDES-BENZ;CLA;5
    3;ALBA;AUTOTURISM;M1     ;MERCEDES BENZ;GTZ6119BEVBF;2
    4;BIHOR;AUTOTURISM;M1     ;MERCEDES-BENZ;GTZ6119BEVBF;3
    CSV;
    $path = tempnam(sys_get_temp_dir(), 'ro-fleet').'.csv';
    file_put_contents($path, $csv);

    $this->artisan('vehicle:import', ['source' => 'ro-fleet', '--year' => 2025, '--file' => $path])->assertExitCode(0);

    $make = Make::query()->where('slug', 'mercedes-benz')->firstOrFail();
    expect($make->ro_fleet_count)->toBe(20);
    $model = VehicleModel::query()->where('slug', 'mercedes-benz-cla')->firstOrFail();
    expect($model->ro_fleet_count)->toBe(15);
    // Provenance now carries two kinds of rows: one per distinct row-level source_ref (ImportPipeline)
    // and one per distinct RAW make/model value (CatalogueIdentity). The make is touched by 2 make-level
    // rows and 2 model-level (CLA) rows sharing the same 2 raw spellings, so it gets 2 row-level rows +
    // 2 raw-value rows = 4. The model is touched by 2 row-level rows (one per raw make spelling) but only
    // 1 raw-value row (the raw model text and the resolved make are the same both times) = 3.
    expect(RecordSource::query()->where('record_type', 'make')->where('record_id', $make->id)->count())->toBe(4)
        ->and(RecordSource::query()->where('record_type', 'model')->where('record_id', $model->id)->count())->toBe(3);

    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->reject_report['summary']['model:type_code'] ?? null)->toBe(2);
});
