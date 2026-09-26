<?php

declare(strict_types=1);

namespace VehicleData\Core\Vin;

/**
 * ISO 3779 position 10 — the model-year character repeats on a 30-year cycle
 * and is ambiguous on its own; position 7 (letter vs digit) picks the band.
 */
final class ModelYear
{
    private const CODES = [
        'A' => 1980, 'B' => 1981, 'C' => 1982, 'D' => 1983, 'E' => 1984, 'F' => 1985, 'G' => 1986, 'H' => 1987, 'J' => 1988, 'K' => 1989, 'L' => 1990, 'M' => 1991,
        'N' => 1992, 'P' => 1993, 'R' => 1994, 'S' => 1995, 'T' => 1996, 'V' => 1997, 'W' => 1998, 'X' => 1999, 'Y' => 2000,
        '1' => 2001, '2' => 2002, '3' => 2003, '4' => 2004, '5' => 2005, '6' => 2006, '7' => 2007, '8' => 2008, '9' => 2009,
    ];

    /** @return list<int> */
    public static function candidates(string $char): array
    {
        $c = strtoupper($char);

        return isset(self::CODES[$c]) ? [self::CODES[$c], self::CODES[$c] + 30] : [];
    }

    public static function resolve(string $vin): ?int
    {
        $vin = strtoupper($vin);
        $candidates = self::candidates($vin[9]);
        if ($candidates === []) {
            return null;
        }

        return ctype_alpha($vin[6]) ? $candidates[1] : $candidates[0];
    }
}
