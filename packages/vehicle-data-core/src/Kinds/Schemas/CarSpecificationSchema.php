<?php

declare(strict_types=1);

namespace VehicleData\Core\Kinds\Schemas;

use Symfony\Component\Yaml\Yaml;
use VehicleData\Core\Contracts\SpecificationSchema;

final class CarSpecificationSchema implements SpecificationSchema
{
    public function kind(): string
    {
        return 'car';
    }

    public function jsonSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'doors' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 6],
                'seats' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9],
                'body_type' => ['type' => 'string'], 'gearbox' => ['type' => 'string'], 'drive' => ['type' => 'string'],
                'euro_stage_raw' => ['type' => 'string', 'maxLength' => 40],
                'fuel_mode' => ['type' => 'string', 'enum' => ['M', 'B', 'F', 'H', 'P', 'E']],
                'co2_nedc' => ['type' => 'integer'], 'electric_range_km' => ['type' => 'integer'], 'energy_wh_km' => ['type' => 'integer'],
                'type_approval_number' => ['type' => 'string', 'maxLength' => 40],
                'type_code' => ['type' => 'string', 'maxLength' => 40], 'variant_code' => ['type' => 'string', 'maxLength' => 40], 'version_code' => ['type' => 'string', 'maxLength' => 40],
                'fuel_consumption_l_100km' => ['type' => 'number'],
            ],
        ];
    }

    public function openApiFragment(): array
    {
        /** @var array<string,mixed> $f */
        $f = Yaml::parseFile(__DIR__.'/../../../resources/openapi/fragments/car.yaml');

        return $f;
    }
}
