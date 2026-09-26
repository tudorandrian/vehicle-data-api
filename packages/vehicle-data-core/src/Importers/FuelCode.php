<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

/**
 * Fuel taxonomy mapping for import sources. Only the EEA (Ft) mapping is needed in v1 - the
 * DRPCIV (Romanian registry) `combustibil` mapping was dropped: no caller uses it until
 * a DRPCIV-backed source is added.
 */
final class FuelCode
{
    private const EEA = ['PETROL' => 'petrol', 'DIESEL' => 'diesel', 'ELECTRIC' => 'electric', 'PETROL/ELECTRIC' => 'petrol_hybrid', 'DIESEL/ELECTRIC' => 'diesel_hybrid',
        'LPG' => 'lpg', 'NG' => 'cng', 'NG-BIOMETHANE' => 'cng', 'HYDROGEN' => 'hydrogen', 'E85' => 'e85'];

    public static function fromEea(string $ft): ?string
    {
        return self::EEA[strtoupper(trim($ft))] ?? null;
    }
}
