<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

interface HasFileChecksum
{
    public function fileChecksum(): ?string;
}
