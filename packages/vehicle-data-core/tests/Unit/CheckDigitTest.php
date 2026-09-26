<?php

declare(strict_types=1);

use VehicleData\Core\Vin\CheckDigit;

it('computes and validates the North-American check digit', function (): void {
    expect(CheckDigit::compute('1HGCM82633A004352'))->toBe('3')->and(CheckDigit::isValid('1HGCM82633A004352'))->toBeTrue()
        ->and(CheckDigit::isValid('1HGCM82634A004352'))->toBeFalse()
        ->and(CheckDigit::compute('11111111111111111'))->toBe('1');
});
