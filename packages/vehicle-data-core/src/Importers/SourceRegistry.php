<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use InvalidArgumentException;
use VehicleData\Core\Contracts\DataSource;

final class SourceRegistry
{
    /** @param array<string, DataSource> $sources keyed by DataSource::key() */
    public function __construct(private readonly array $sources = []) {}

    public function get(string $key): DataSource
    {
        return $this->sources[$key] ?? throw new InvalidArgumentException("Unknown data source [{$key}]. Known: ".implode(', ', $this->keys()));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->sources);
    }
}
