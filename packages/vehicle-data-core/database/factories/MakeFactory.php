<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Support\Slug;

/**
 * @extends Factory<Make>
 */
final class MakeFactory extends Factory
{
    protected $model = Make::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->lastName();

        return [
            'slug' => Slug::make($name),
            'name' => $name,
            'kind' => 'car',
            'manufacturer_id' => Manufacturer::factory(),
        ];
    }
}
