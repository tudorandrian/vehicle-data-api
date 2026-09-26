<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

use VehicleData\Core\Auth\ResolvedClient;

final readonly class EnrichmentContext
{
    public function __construct(public string $lang, public ?ResolvedClient $client) {}
}
