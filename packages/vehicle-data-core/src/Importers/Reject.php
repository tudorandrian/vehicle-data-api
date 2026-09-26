<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final readonly class Reject
{
    public function __construct(public string $rule) {}
}
