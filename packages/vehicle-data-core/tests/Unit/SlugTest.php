<?php

declare(strict_types=1);

use VehicleData\Core\Support\Slug;

it('slugifies Romanian and European names to hyphenated ASCII', function (string $in, string $out): void {
    expect(Slug::make($in))->toBe($out);
})->with([
    ['Mercedes-Benz', 'mercedes-benz'],
    ['Škoda', 'skoda'],
    ['Citroën', 'citroen'],
    ['  Alfa   Romeo ', 'alfa-romeo'],
    ['Dacia 1310 TX', 'dacia-1310-tx'],
    ['ȘTEFAN & Co.', 'stefan-co'],
]);
