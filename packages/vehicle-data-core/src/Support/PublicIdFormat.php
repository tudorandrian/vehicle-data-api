<?php

declare(strict_types=1);

namespace VehicleData\Core\Support;

use Illuminate\Support\Str;

/**
 * The public identifier of every catalogue record: 26-character Crockford base32 (the same
 * alphabet PublicId uses for its deterministic ids), assigned once at insert and never
 * recomputed (ADR 0007). `generate()` below produces a ULID-encoded value - used only as the
 * fallback when no provenance ref is available (factories, tests) - but the public format
 * itself makes no ULID claim: a record minted from a provenance ref (ADR 0007 addendum) gets
 * a SHA-256-derived id in the same 26-character alphabet, not a ULID.
 */
final class PublicIdFormat
{
    public const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    public static function looksLike(string $candidate): bool
    {
        return preg_match(self::PATTERN, $candidate) === 1;
    }
}
