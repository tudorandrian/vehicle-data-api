<?php

declare(strict_types=1);

use VehicleData\Core\Auth\ApiKey;

it('generates vd_live_ keys with 40 base62 characters', function (): void {
    $key = ApiKey::generate();
    expect($key)->toMatch('/^vd_live_[0-9A-Za-z]{40}$/')
        ->and(ApiKey::looksValid($key))->toBeTrue()
        ->and(ApiKey::prefix($key))->toBe(substr($key, 8, 8))
        ->and(ApiKey::hash($key))->toBe(hash('sha256', $key));
});

it('rejects malformed keys cheaply', function (): void {
    expect(ApiKey::looksValid('vd_live_short'))->toBeFalse()->and(ApiKey::looksValid(''))->toBeFalse();
});
