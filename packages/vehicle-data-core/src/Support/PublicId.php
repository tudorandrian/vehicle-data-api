<?php

declare(strict_types=1);

namespace VehicleData\Core\Support;

final class PublicId
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * 26-character Crockford base32 of the first 130 bits of SHA-256(natural key). Stable for
     * the life of the record. Deterministic public id: used for variants (natural key) and,
     * since Phase 2, for the first minting of every catalogue and taxonomy record from its
     * provenance ref (ADR 0007 addendum).
     */
    public static function for(string $naturalKey): string
    {
        $bits = '';
        foreach (str_split(substr(hash('sha256', $naturalKey, true), 0, 17)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < 26; $i++) {
            $out .= self::ALPHABET[(int) bindec(substr($bits, $i * 5, 5))];
        }

        return $out;
    }
}
