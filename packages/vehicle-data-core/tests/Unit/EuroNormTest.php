<?php

declare(strict_types=1);

use VehicleData\Core\Importers\EuroNorm;

it('derives the emission stage from the EEA Ech code', function (?string $ech, ?string $expected): void {
    expect(EuroNorm::fromStage($ech))->toBe($expected);
})->with([
    ['6AP', 'euro_6d'], ['EURO 6 AP', 'euro_6d'], ['EURO 6(AP)', 'euro_6d'], ['AP', 'euro_6d'], ['AX', 'euro_6d'], ['AZ', 'euro_6d'], ['Euro6d-ISC-FCM, AP', 'euro_6d'],
    ['6 EA', 'euro_6e'], ['EURO 6 EA', 'euro_6e'], ['EURO 6(EB)', 'euro_6e'], ['Euro6e, EA', 'euro_6e'], ['EA', 'euro_6e'],
    ['EURO 6', 'euro_6'], ['6W', 'euro_6'], ['Euro VI(E)', 'euro_6'], ['VI E', 'euro_6'],
    ['EURO 5', 'euro_5'], ['5J', 'euro_5'], ['EU 4B', 'euro_4'], ['', null], [null, null], ['70/220; R83.05 RII', null],
]);
