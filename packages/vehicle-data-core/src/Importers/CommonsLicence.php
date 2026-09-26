<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

/**
 * Gate for Wikimedia Commons `extmetadata.LicenseShortName` values: only public-domain and
 * non-share-alike Creative Commons licences are free enough to store a logo for. Anything
 * containing "SA" (case-insensitive) - CC BY-SA, CC BY-NC-SA - is rejected, along with
 * NC/ND-only licences, GFDL, "Fair use" and missing/empty values.
 */
final class CommonsLicence
{
    public static function isFree(?string $shortName): bool
    {
        $s = strtoupper(trim((string) $shortName));
        if ($s === '' || str_contains($s, 'SA')) {
            return false;
        }

        // "CC BY-NC ..." and "CC BY-ND ..." pass the "CC BY" prefix check below but are not free
        // enough (non-commercial / no-derivatives); catch the hyphen- and space-joined variants as
        // whole tokens so "CC BY 4.0" (no NC/ND) is still accepted.
        $tokens = preg_split('/[\s-]+/', $s) ?: [];
        if (array_intersect(['NC', 'ND'], $tokens) !== []) {
            return false;
        }

        return $s === 'PUBLIC DOMAIN' || str_starts_with($s, 'CC0') || str_starts_with($s, 'CC BY') || str_starts_with($s, 'PD');
    }
}
