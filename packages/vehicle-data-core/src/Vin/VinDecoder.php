<?php

declare(strict_types=1);

namespace VehicleData\Core\Vin;

use VehicleData\Core\Models\Wmi;

final class VinDecoder
{
    public const NOTE = 'Structural decode per ISO 3779 (WMI, check digit, model-year character, plant code). This is not a registration or history lookup.';

    public function decode(string $vin): VinResult
    {
        $vin = strtoupper(trim($vin));
        if (preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) !== 1) {
            throw new InvalidVin('A VIN is exactly 17 characters from A–Z (without I, O, Q) and 0–9.');
        }
        $wmi = substr($vin, 0, 3);
        $region = self::region($vin[0]);
        $known = Wmi::query()->where('code', $wmi)->first();
        $applies = $region === 'north_america';
        $expected = CheckDigit::compute($vin);
        $valid = $expected === $vin[8];
        $confidence = match (true) {
            $known !== null && (! $applies || $valid) => 'high',
            $known !== null || ($applies && $valid) => 'medium',
            default => 'low',
        };

        return new VinResult(
            vin: $vin,
            wmi: $wmi,
            vds: substr($vin, 3, 6),
            vis: substr($vin, 9),
            region: $region,
            manufacturer: $known === null ? null : ['name' => $known->manufacturer_name, 'country_code' => $known->country_code],
            checkDigit: ['applies' => $applies, 'expected' => $expected, 'actual' => $vin[8], 'valid' => $valid],
            modelYear: ['character' => $vin[9], 'candidates' => ModelYear::candidates($vin[9]), 'resolved' => ModelYear::resolve($vin)],
            plantCode: $vin[10],
            serial: substr($vin, 11),
            confidence: $confidence,
            note: self::NOTE,
        );
    }

    private static function region(string $first): string
    {
        return match (true) {
            $first >= 'A' && $first <= 'H' => 'africa',
            $first >= 'J' && $first <= 'R' => 'asia',
            $first >= 'S' && $first <= 'Z' => 'europe',
            $first >= '1' && $first <= '5' => 'north_america',
            $first === '6' || $first === '7' => 'oceania',
            $first === '8' || $first === '9' => 'south_america',
            default => 'unknown',
        };
    }
}
