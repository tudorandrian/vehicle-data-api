<?php

declare(strict_types=1);

use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\Wmi;

it('imports three-character WMIs with ISO country codes', function (): void {
    $this->artisan('vehicle:import', ['source' => 'wmi', '--file' => base_path('packages/vehicle-data-core/database/fixtures/vpic_wmi.json')])->assertExitCode(0);
    $wvw = Wmi::query()->where('code', 'WVW')->firstOrFail();
    expect($wvw->manufacturer_name)->toBe('VOLKSWAGEN AG')->and($wvw->country_code)->toBe('DE')
        ->and(Wmi::query()->whereRaw('LENGTH(code) <> 3')->count())->toBe(0)->and(Wmi::count())->toBeGreaterThan(50);
});

it('rejects a three-character WMI that is not alphanumeric with rule wmi_format, distinct from wmi_length', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'wmi').'.json';
    file_put_contents($tmp, json_encode(['results' => [
        ['WMI' => 'W-V', 'Name' => 'Bogus Motors', 'Country' => 'GERMANY', 'VehicleType' => 'Passenger Car'],
        ['WMI' => 'WVW', 'Name' => 'VOLKSWAGEN AG', 'Country' => 'GERMANY', 'VehicleType' => 'Passenger Car'],
    ]]));

    $this->artisan('vehicle:import', ['source' => 'wmi', '--file' => $tmp])->assertExitCode(0);

    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->reject_report['summary']['wmi_format'] ?? 0)->toBe(1)
        ->and($run->reject_report['summary']['wmi_length'] ?? 0)->toBe(0)
        ->and(Wmi::query()->where('code', 'WVW')->exists())->toBeTrue()
        ->and(Wmi::query()->where('code', 'W-V')->exists())->toBeFalse();
});
