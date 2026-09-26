<?php

declare(strict_types=1);

use VehicleData\Core\Support\PublicId;

it('is deterministic, 26 characters, Crockford base32', function (): void {
    $a = PublicId::for('eea|DACIA|DUSTER|petrol|1332|110|M1');
    $b = PublicId::for('eea|DACIA|DUSTER|petrol|1332|110|M1');
    expect($a)->toBe($b)->toHaveLength(26)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('changes when any part of the natural key changes', function (): void {
    expect(PublicId::for('a|b|c'))->not->toBe(PublicId::for('a|b|d'));
});
