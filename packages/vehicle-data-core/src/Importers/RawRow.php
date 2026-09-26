<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final class RawRow
{
    /** @param array<string,mixed> $data */
    public function __construct(public readonly array $data, public readonly string $ref, public ?string $rejectRule = null) {}

    public function reject(string $rule): null
    {
        $this->rejectRule = $rule;

        return null;
    }
}
