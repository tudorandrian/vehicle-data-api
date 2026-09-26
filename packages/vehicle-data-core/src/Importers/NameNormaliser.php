<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use Illuminate\Support\Str;

/**
 * Ordered rules (documented in docs/data-sources.md):
 *  1. trim + collapse whitespace; empty → reject "empty"; placeholder tokens → reject "placeholder"; > 120 chars → reject "too_long"
 *  2. aliases (VW → Volkswagen, MERCEDES BENZ → Mercedes-Benz, SKODA → Škoda, CITROEN → Citroën, ROLLS ROYCE → Rolls-Royce)
 *  3. acronyms kept upper-case (BMW, DS, MG, SEAT, MINI, BYD, MAN, DAF, GMC, RAM, MCV, SW, TX, …)
 *  4. everything else Title Case; hyphenated parts title-cased separately
 *  5. models only: strip a trailing engine/drivetrain code (e.g. "2.0 TDI", "1.6 T-GDI", "XDRIVE30D"); ≥ 8 alphanumerics with digits and no space → reject "type_code"
 */
final class NameNormaliser
{
    private const PLACEHOLDERS = ['-', '--', 'N/A', 'NA', 'NONE', 'UNKNOWN', 'DUPLICATE', 'NECUNOSCUT', 'NEDEFINIT', '.'];

    private const ALIASES = [
        'VW' => 'Volkswagen', 'VOLKSWAGEN' => 'Volkswagen', 'MERCEDES' => 'Mercedes-Benz', 'MERCEDES BENZ' => 'Mercedes-Benz', 'MERCEDES-BENZ' => 'Mercedes-Benz',
        'SKODA' => 'Škoda', 'ŠKODA' => 'Škoda', 'CITROEN' => 'Citroën', 'CITROËN' => 'Citroën', 'ROLLS ROYCE' => 'Rolls-Royce', 'ROLLS-ROYCE' => 'Rolls-Royce', 'LAND-ROVER' => 'Land Rover',
    ];

    private const ACRONYMS = ['BMW', 'DS', 'MG', 'SEAT', 'MINI', 'BYD', 'MAN', 'DAF', 'GMC', 'RAM', 'MCV', 'SW', 'TX', 'GT', 'GTI', 'RS', 'ST', 'AMG', 'XC', 'CX', 'MX', 'NX', 'RX', 'UX', 'ID', 'EV', 'EQ', 'CLA', 'CLS', 'GLA', 'GLB', 'GLC', 'GLE', 'GLS', 'SLK', 'SL', 'GLK', 'MPV', 'SUV', 'XL', 'XXL', 'LWB', 'SWB', 'HR', 'CR', 'CH', 'RAV', 'X'];

    private const ENGINE_CODE = '/\s+(\d\.\d\s*[A-Z-]{0,8}|\d{2,3}\s?(CV|HP|KW)|[A-Z]?DRIVE\d{2}[A-Z]?|TDI|TSI|TFSI|DCI|HDI|CDI|CRDI|T-GDI|GDI|MHEV|PHEV|HYBRID|4X4|AWD|4WD|4MATIC|QUATTRO|XDRIVE|ECOBOOST|BLUEHDI|PURETECH|TCE|SCE|DIG-T)(\s+.*)?$/iu';

    public static function make(string $raw): string|Reject
    {
        $s = self::basic($raw);
        if ($s instanceof Reject) {
            return $s;
        }
        $upper = mb_strtoupper($s);
        if (isset(self::ALIASES[$upper])) {
            return self::ALIASES[$upper];
        }

        return self::titleCase($s);
    }

    public static function model(string $raw): string|Reject
    {
        $s = self::basic($raw);
        if ($s instanceof Reject) {
            return $s;
        }
        $stripped = preg_replace(self::ENGINE_CODE, '', $s) ?? $s;
        $s = trim($stripped) === '' ? $s : trim($stripped);
        if (preg_match('/^[A-Z0-9]{8,}$/u', $s) === 1 && preg_match('/\d/', $s) === 1 && preg_match('/[AEIOU]{2}/', $s) !== 1) {
            return new Reject('type_code');
        }

        return self::titleCase($s);
    }

    private static function basic(string $raw): string|Reject
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($s === '') {
            return new Reject('empty');
        }
        if (in_array(mb_strtoupper($s), self::PLACEHOLDERS, true)) {
            return new Reject('placeholder');
        }
        if (mb_strlen($s) > 120) {
            return new Reject('too_long');
        }

        return $s;
    }

    private static function titleCase(string $s): string
    {
        $words = explode(' ', $s);
        foreach ($words as &$w) {
            $parts = explode('-', $w);
            foreach ($parts as &$p) {
                $u = mb_strtoupper($p);
                $p = in_array($u, self::ACRONYMS, true) || preg_match('/^[A-Z]{1,2}\d/u', $u) === 1 || preg_match('/^\d/u', $u) === 1 || str_contains($u, '.')
                    ? ($u === $p || preg_match('/\d/', $u) === 1 || str_contains($u, '.') ? $u : $p)
                    : Str::ucfirst(mb_strtolower($p));
            }
            $w = implode('-', $parts);
        }

        return implode(' ', $words);
    }
}
