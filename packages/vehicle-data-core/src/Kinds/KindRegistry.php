<?php

declare(strict_types=1);

namespace VehicleData\Core\Kinds;

use InvalidArgumentException;
use VehicleData\Core\Contracts\SpecificationSchema;

final class KindRegistry
{
    /** @var array<string, SpecificationSchema> */
    private array $schemas = [];

    public function register(SpecificationSchema $schema): void
    {
        $this->schemas[$schema->kind()] = $schema;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_keys($this->schemas);
    }

    public function has(string $kind): bool
    {
        return isset($this->schemas[$kind]);
    }

    public function schema(string $kind): SpecificationSchema
    {
        return $this->schemas[$kind] ?? throw new InvalidArgumentException("Unknown kind '{$kind}'.");
    }
}
