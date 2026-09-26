<?php

declare(strict_types=1);

use VehicleData\Core\Vin\ModelYear;

it('lists both 30-year candidates and resolves with the position-7 rule', function (): void {
    expect(ModelYear::candidates('A'))->toBe([1980, 2010])->and(ModelYear::candidates('3'))->toBe([2003, 2033])->and(ModelYear::candidates('Y'))->toBe([2000, 2030])
        ->and(ModelYear::candidates('U'))->toBe([])
        ->and(ModelYear::resolve('1HGCM82633A004352'))->toBe(2003)   // position 7 = '2' (digit) → 1980–2009 band
        ->and(ModelYear::resolve('5YJ3E1EA7KF317000'))->toBe(2019)   // position 7 = 'E' (letter) → 2010–2039 band
        ->and(ModelYear::resolve('WVWZZZ3CZWE689725'))->toBe(1998);  // 'W' → 1998 or 2028; position 7 '3' → 1998
});
