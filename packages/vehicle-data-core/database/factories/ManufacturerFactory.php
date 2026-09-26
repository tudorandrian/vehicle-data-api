<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Support\Slug;

/**
 * @extends Factory<Manufacturer>
 */
final class ManufacturerFactory extends Factory
{
    protected $model = Manufacturer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Slug::make($name),
            'name' => $name,
            'country_code' => fake()->countryCode(),
            'founded_year' => fake()->numberBetween(1890, 2020),
            'wikidata_qid' => 'Q'.fake()->unique()->numberBetween(1000, 99999999),
            'website' => fake()->url(),
        ];
    }
}
