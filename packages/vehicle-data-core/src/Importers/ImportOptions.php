<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final readonly class ImportOptions
{
    public function __construct(
        public ?int $year = null,
        public ?int $limit = null,
        public bool $force = false,
        public ?string $file = null,
        public float $rejectShare = 0.05,
    ) {}
}
