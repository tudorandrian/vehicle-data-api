<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\PublicId;

/**
 * @extends Factory<Variant>
 */
final class VariantFactory extends Factory
{
    protected $model = Variant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => PublicId::for(fake()->unique()->uuid()),
            'model_id' => VehicleModel::factory(),
            'fuel_code' => 'petrol',
            'eu_category_code' => 'm1',
            'euro_norm_code' => 'euro_6d',
            'engine_cc' => 1332,
            'power_kw' => 110,
            'mass_kg' => 1403,
            'co2_wltp' => 154,
            'year_from' => 2024,
            'year_to' => null,
            'specifications' => null,
        ];
    }
}
