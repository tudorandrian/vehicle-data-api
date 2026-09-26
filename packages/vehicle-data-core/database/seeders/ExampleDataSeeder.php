<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\ImportPipeline;
use VehicleData\Core\Importers\SourceRegistry;

/**
 * Seeds a demo-ready catalogue by importing the four committed fixtures through the real
 * `ImportPipeline` - not hand-typed rows - so every seeded record carries the same
 * normalisation, taxonomy validation and provenance (`vd_record_sources`) a live import
 * would produce. Runs in dependency order: eea (variants/makes/models) and ro-fleet
 * (ro_fleet_count on makes/models) first, so wikidata and wmi's own writers see the makes
 * those two sources create. Idempotent: every writer in the pipeline uses
 * firstOrCreate/updateOrCreate, so re-running this seeder (e.g. via `migrate:fresh --seed`
 * twice, or the seeder test itself) leaves row counts unchanged.
 */
final class ExampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([TaxonomySeeder::class, SourceSeeder::class]);

        $fixtures = __DIR__.'/../fixtures/';
        $pipeline = app(ImportPipeline::class);
        $sources = app(SourceRegistry::class);

        $pipeline->run($sources->get('eea'), new ImportOptions(year: 2024, file: $fixtures.'eea_ro_2024.json'));
        $pipeline->run($sources->get('ro-fleet'), new ImportOptions(year: 2025, file: $fixtures.'ro_fleet_2025.csv'));
        $pipeline->run($sources->get('wikidata'), new ImportOptions(file: $fixtures.'wikidata_manufacturers.json'));
        $pipeline->run($sources->get('wmi'), new ImportOptions(file: $fixtures.'vpic_wmi.json', rejectShare: (float) config('core.import_reject_share_wmi', 1.0)));
    }
}
