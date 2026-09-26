<?php

declare(strict_types=1);

namespace VehicleData\Core\Vin;

/**
 * ISO 3779 / FMVSS 115 (49 CFR 565) check digit — position 9 of a North-American VIN.
 */
final class CheckDigit
{
    /** @var array<string, int> */
    private const VALUES = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8, 'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
    ];

    /** @var list<int> */
    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    public static function compute(string $vin): string
    {
        $sum = 0;
        foreach (str_split(strtoupper($vin)) as $i => $c) {
            $sum += (ctype_digit($c) ? (int) $c : (self::VALUES[$c] ?? 0)) * self::WEIGHTS[$i];
        }
        $r = $sum % 11;

        return $r === 10 ? 'X' : (string) $r;
    }

    public static function isValid(string $vin): bool
    {
        return strlen($vin) === 17 && self::compute($vin) === strtoupper($vin)[8];
    }
}
