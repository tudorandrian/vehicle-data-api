<?php

declare(strict_types=1);

use VehicleData\Core\Support\PublicIdFormat;

it('generates 26-character crockford base32 ids that are unique', function (): void {
    $a = PublicIdFormat::generate();
    $b = PublicIdFormat::generate();
    expect($a)->toMatch(PublicIdFormat::PATTERN)->and($b)->toMatch(PublicIdFormat::PATTERN)->and($a)->not->toBe($b);
});

it('recognises an id and rejects slugs and near misses', function (): void {
    expect(PublicIdFormat::looksLike('01J8ZQ3W7S5K4M2N9P6R8T1V0X'))->toBeTrue()
        ->and(PublicIdFormat::looksLike('dacia'))->toBeFalse()
        ->and(PublicIdFormat::looksLike('01J8ZQ3W7S5K4M2N9P6R8T1V0I'))->toBeFalse() // I is not in the alphabet
        ->and(PublicIdFormat::looksLike(strtolower('01J8ZQ3W7S5K4M2N9P6R8T1V0X')))->toBeFalse();
});
