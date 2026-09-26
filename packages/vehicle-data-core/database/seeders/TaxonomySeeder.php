<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyLabel;
use VehicleData\Core\Models\TaxonomyTerm;
use VehicleData\Core\Support\PublicId;
use VehicleData\Core\Taxonomies\TaxonomyDefinitions;

final class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $locales = (array) config('core.locales', ['ro', 'en']);

        foreach (TaxonomyDefinitions::all() as $name => $def) {
            $taxonomy = Taxonomy::query()->firstOrCreate(['name' => $name], ['description' => $def['description'], 'public_id' => PublicId::for("taxonomy|$name")]);
            if ($taxonomy->description !== $def['description']) {
                $taxonomy->update(['description' => $def['description']]);
            }

            foreach ($def['terms'] as $i => $code) {
                $term = TaxonomyTerm::query()->firstOrCreate(
                    ['taxonomy_id' => $taxonomy->id, 'code' => $code],
                    ['sort_order' => $i, 'parent_code' => null, 'public_id' => PublicId::for("term|$name|$code")],
                );
                if ($term->sort_order !== $i) {
                    $term->update(['sort_order' => $i]);
                }

                foreach ($locales as $locale) {
                    $key = "core::taxonomies.$name.$code";
                    $label = trans($key, [], $locale);
                    if ($label === $key) {
                        throw new RuntimeException("Missing $locale label for $name.$code");
                    }
                    TaxonomyLabel::query()->updateOrCreate(['term_id' => $term->id, 'locale' => $locale], ['label' => $label]);
                }
            }
        }
    }
}
