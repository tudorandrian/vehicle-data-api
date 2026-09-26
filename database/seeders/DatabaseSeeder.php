<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use VehicleData\Core\Database\Seeders\CoreSeeder;

class DatabaseSeeder extends Seeder
{
    // Intentionally does NOT `use WithoutModelEvents;` (the Laravel skeleton default):
    // catalogue records get their immutable `public_id` from a `creating` model event
    // (HasPublicId, ADR 0007), and that trait suppresses model events — including this
    // one — for every seeder this class calls, so `php artisan migrate --seed` would
    // otherwise fail with "Field 'public_id' doesn't have a default value" the moment
    // ExampleDataSeeder's import pipeline creates the first make/model/manufacturer.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CoreSeeder::class);
    }
}
