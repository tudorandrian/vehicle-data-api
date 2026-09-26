<?php

declare(strict_types=1);

namespace VehicleData\Core\Support;

/** Licence ids a source may carry (ADR 0005: attribution-only or public domain; never share-alike or proprietary). */
final class AdmittedLicences
{
    /** @var list<string> */
    public const IDS = ['CC-BY-4.0', 'OGL-ROU-1.0', 'CC0-1.0', 'US-PD'];

    public static function admits(string $licenceId): bool
    {
        return in_array($licenceId, self::IDS, true);
    }
}
