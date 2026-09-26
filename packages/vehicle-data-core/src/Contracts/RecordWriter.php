<?php

declare(strict_types=1);

namespace VehicleData\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Models\Source;

interface RecordWriter
{
    public function type(): string;

    /**
     * Upsert by natural key; return every model touched (for provenance).
     *
     * $retrievedAt is the run's single captured instant (ImportPipeline::run()): pass it through
     * to CatalogueIdentity::make()/model() rather than calling now(), so their own provenance
     * writes stay constant for the whole run instead of firing an UPDATE per row.
     *
     * @return list<Model>
     */
    public function write(DomainRow $row, Source $source, \DateTimeInterface $retrievedAt): array;
}
