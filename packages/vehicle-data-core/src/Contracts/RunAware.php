<?php

declare(strict_types=1);

namespace VehicleData\Core\Contracts;

use VehicleData\Core\Models\Source;

/**
 * Optional companion to RecordWriter: implement it when a writer needs one pass over the
 * whole run's data after every row has been written (e.g. resolving cross-row references
 * that don't depend on import order). ImportPipeline::run() calls finish() for every writer
 * that implements it, once per run, after the last flush and still inside the run's
 * transaction - so it never runs for an aborted or failed import.
 */
interface RunAware
{
    public function finish(Source $source): void;
}
