<?php

declare(strict_types=1);

use VehicleData\Core\Models\ApiRequest;

it('decodes with the vin scope, resolves the manufacturer from vd_wmi, and 400s malformed input', function (): void {
    $this->artisan('vehicle:import', ['source' => 'wmi', '--file' => base_path('packages/vehicle-data-core/database/fixtures/vpic_wmi.json')]);
    [, $key] = keyed(['vin:decode']);
    $this->getJson('/v1/vin/WVWZZZ3CZWE689725', bearer($key))->assertOk()->assertJsonPath('data.manufacturer.name', 'VOLKSWAGEN AG')->assertJsonPath('data.confidence', 'high')->assertJsonPath('sources.0.key', 'wmi');
    // A malformed VIN is a 400 problem, not a 422.
    $this->getJson('/v1/vin/NOTAVIN', bearer($key))->assertStatus(400)->assertJsonPath('type', '/problems/malformed-vin');
    [, $cat] = keyed(['catalogue:read']);
    $this->getJson('/v1/vin/WVWZZZ3CZWE689725', bearer($cat))->assertStatus(403);
});

it('never persists the full VIN in the usage log — only the route name', function (): void {
    $this->artisan('vehicle:import', ['source' => 'wmi', '--file' => base_path('packages/vehicle-data-core/database/fixtures/vpic_wmi.json')]);
    [, $key] = keyed(['vin:decode']);
    $this->getJson('/v1/vin/WVWZZZ3CZWE689725', bearer($key))->assertOk();

    $row = ApiRequest::query()->latest('id')->firstOrFail();
    expect($row->route)->toBe('v1.vin.show')->and($row->route)->not->toContain('WVWZZZ3CZWE689725');
});
