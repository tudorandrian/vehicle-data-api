<?php

declare(strict_types=1);

use VehicleData\Core\Http\Query\Fields;

it('keeps class-2 keys present with null', function (): void {
    expect(Fields::required(['a' => 1], ['a', 'b']))->toBe(['a' => 1, 'b' => null]);
});

it('drops class-3 keys that are null', function (): void {
    expect(Fields::optional(['ro_fleet' => null, 'logo' => ['f' => 1]]))->toBe(['logo' => ['f' => 1]]);
});

it('applies sparse fieldsets but never drops identity keys', function (): void {
    expect(Fields::sparse(['slug' => 's', 'name' => 'n', 'website' => 'w', 'country_code' => 'RO'], ['website'], ['slug']))->toBe(['slug' => 's', 'website' => 'w']);
    expect(Fields::sparse(['slug' => 's', 'name' => 'n'], [], ['slug']))->toBe(['slug' => 's', 'name' => 'n']);
});
