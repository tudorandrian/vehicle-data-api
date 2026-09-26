<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final readonly class DomainRow
{
    /**
     * @param  'manufacturer'|'variant'|'fleet'|'wmi'  $type
     * @param  array<string,mixed>  $attributes
     */
    public function __construct(public string $type, public array $attributes, public string $sourceRef) {}
}
