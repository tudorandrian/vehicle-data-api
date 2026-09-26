<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use VehicleData\Core\Importers\SourceRegistry;
use VehicleData\Core\Models\Source;

/**
 * Source names, urls and licences have one source of truth: each registered
 * DataSource's own key()/name()/url()/licence(), read from the SourceRegistry
 * singleton (Tasks 11–13 register the real eea/ro-fleet/wikidata/wmi sources
 * there). With no sources registered this seeder writes nothing.
 */
final class SourceSeeder extends Seeder
{
    public function run(SourceRegistry $registry): void
    {
        foreach ($registry->keys() as $key) {
            $source = $registry->get($key);
            $licence = $source->licence();

            Source::query()->updateOrCreate(['key' => $key], [
                'name' => $source->name(),
                'licence_id' => $licence->id,
                'licence_name' => $licence->name,
                'licence_url' => $licence->url,
                'attribution' => $licence->attribution,
                'url' => $source->url(),
            ]);
        }
    }
}
