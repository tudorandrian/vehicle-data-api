<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use RuntimeException;

/** Thrown by a writer to reject the current row with a rule name; the pipeline counts it like a map reject. */
final class RowRejected extends RuntimeException
{
    public function __construct(public readonly string $rule)
    {
        parent::__construct($rule);
    }
}
