<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use RuntimeException;
use VehicleData\Core\Support\AdmittedLicences;

final class LicenceNotAdmitted extends RuntimeException
{
    public function __construct(public readonly string $sourceKey, public readonly string $licenceId)
    {
        parent::__construct(sprintf('Source %s carries licence %s, which is not admitted (%s). Nothing was imported.', $sourceKey, $licenceId, implode(', ', AdmittedLicences::IDS)));
    }
}
