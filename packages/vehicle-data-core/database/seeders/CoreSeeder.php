<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Seeders;

use Illuminate\Database\Seeder;

final class CoreSeeder extends Seeder
{
    public function run(): void
    {
        // ExampleDataSeeder already calls TaxonomySeeder and SourceSeeder itself (so it stays
        // usable standalone, e.g. from tests that seed it directly without CoreSeeder) -
        // calling them again here would just re-run them a second time for nothing.
        $this->call([ExampleDataSeeder::class]);
    }
}
