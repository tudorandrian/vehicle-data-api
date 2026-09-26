<?php

declare(strict_types=1);

use VehicleData\Core\Support\Power;

it('converts kW to metric hp and keeps null', function (): void {
    expect(Power::hp(110))->toBe(150)->and(Power::hp(96))->toBe(131)->and(Power::hp(null))->toBeNull();
});
