<?php

declare(strict_types=1);

// VinDecoder queries the vd_wmi table, so this test needs the database -
// it lives under Feature (RefreshDatabase), not Unit.

use VehicleData\Core\Vin\InvalidVin;
use VehicleData\Core\Vin\VinDecoder;

it('decodes structure, region and confidence without a WMI table', function (): void {
    $r = (new VinDecoder)->decode('1hgcm82633a004352')->toArray();
    expect($r)->toMatchArray(['vin' => '1HGCM82633A004352', 'wmi' => '1HG', 'vds' => 'CM8263', 'vis' => '3A004352', 'region' => 'north_america', 'plant_code' => 'A', 'serial' => '004352', 'manufacturer' => null])
        ->and($r['check_digit'])->toBe(['applies' => true, 'expected' => '3', 'actual' => '3', 'valid' => true])
        ->and($r['model_year']['resolved'])->toBe(2003)->and($r['confidence'])->toBe('medium');
    $eu = (new VinDecoder)->decode('WVWZZZ3CZWE689725')->toArray();
    expect($eu['region'])->toBe('europe')->and($eu['check_digit']['applies'])->toBeFalse()->and($eu['confidence'])->toBe('low');
});

it('rejects malformed input', function (string $vin): void {
    expect(fn () => (new VinDecoder)->decode($vin))->toThrow(InvalidVin::class);
})->with(['short', '1HGCM82633A00435Q', '1HGCM82633A0043521', 'WVWZZZ3CZWE68972I', '']);
