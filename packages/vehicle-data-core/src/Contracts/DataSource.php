<?php

declare(strict_types=1);

namespace VehicleData\Core\Contracts;

use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\RawRow;

interface DataSource
{
    public function key(): string;

    public function name(): string;

    public function url(): string;

    public function licence(): Licence;

    /**
     * Stream rows; must honour $options->limit and $options->file (fixture path).
     *
     * @return iterable<RawRow>
     */
    public function fetch(ImportOptions $options): iterable;

    /** Return null to reject; call $row->reject('rule') first. */
    public function map(RawRow $row): ?DomainRow;

    public function naturalKey(DomainRow $row): string;
}
