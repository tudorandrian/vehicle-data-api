<?php

declare(strict_types=1);

use VehicleData\Core\Importers\NameNormaliser;
use VehicleData\Core\Importers\Reject;

it('normalises make names by documented rules', function (string $raw, string $expected): void {
    expect(NameNormaliser::make($raw))->toBe($expected);
})->with([
    ['DACIA', 'Dacia'], ['  mercedes-benz ', 'Mercedes-Benz'], ['MERCEDES BENZ', 'Mercedes-Benz'], ['VW', 'Volkswagen'], ['VOLKSWAGEN', 'Volkswagen'],
    ['BMW', 'BMW'], ['KIA', 'Kia'], ['SKODA', 'Škoda'], ['ŠKODA', 'Škoda'], ['CITROEN', 'Citroën'], ['DS', 'DS'], ['MG', 'MG'], ['SEAT', 'SEAT'], ['MINI', 'MINI'],
    ['ALFA ROMEO', 'Alfa Romeo'], ['LAND ROVER', 'Land Rover'], ['ROLLS ROYCE', 'Rolls-Royce'], ['BYD', 'BYD'], ['MAN', 'MAN'], ['DAF', 'DAF'],
]);

it('rejects empty and placeholder makes with the rule name', function (string $raw, string $rule): void {
    $r = NameNormaliser::make($raw);
    expect($r)->toBeInstanceOf(Reject::class)->and($r->rule)->toBe($rule);
})->with([['', 'empty'], ['   ', 'empty'], ['-', 'placeholder'], ['N/A', 'placeholder'], ['UNKNOWN', 'placeholder'], ['DUPLICATE', 'placeholder'], [str_repeat('A', 121), 'too_long']]);

it('normalises model names, keeps digits and strips trailing engine codes', function (string $raw, string $expected): void {
    expect(NameNormaliser::model($raw))->toBe($expected);
})->with([
    ['DUSTER', 'Duster'], ['1310 TX', '1310 TX'], ['GOLF 2.0 TDI', 'Golf'], ['SPORTAGE 1.6 T-GDI', 'Sportage'], ['MODEL 3', 'Model 3'], ['C-HR', 'C-HR'], ['ID.4', 'ID.4'],
    ['E-BUS 9M', 'E-Bus 9M'], ['LOGAN MCV', 'Logan MCV'], ['X5 XDRIVE30D', 'X5'], ['308 SW', '308 SW'],
]);

it('rejects model names that are empty or look like a type code', function (): void {
    expect(NameNormaliser::model(''))->toBeInstanceOf(Reject::class)
        ->and(NameNormaliser::model('GTZ6119BEVBF')->rule)->toBe('type_code');
});
