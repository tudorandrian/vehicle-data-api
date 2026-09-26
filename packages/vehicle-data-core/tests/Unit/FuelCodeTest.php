<?php

declare(strict_types=1);

use VehicleData\Core\Importers\FuelCode;

it('maps EEA Ft values', function (string $ft, ?string $code): void {
    expect(FuelCode::fromEea($ft))->toBe($code);
})->with([['petrol', 'petrol'], ['DIESEL', 'diesel'], ['electric', 'electric'], ['petrol/electric', 'petrol_hybrid'], ['diesel/electric', 'diesel_hybrid'], ['lpg', 'lpg'], ['ng', 'cng'], ['ng-biomethane', 'cng'], ['hydrogen', 'hydrogen'], ['e85', 'e85'], ['unknown', null], ['', null]]);
