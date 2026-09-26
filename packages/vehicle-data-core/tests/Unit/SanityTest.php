<?php

declare(strict_types=1);

use VehicleData\Core\Providers\CoreServiceProvider;

it('autoloads the core package', function (): void {
    expect(class_exists(CoreServiceProvider::class))->toBeTrue();
});

it('merges the core config', function (): void {
    expect(config('core.default_locale'))->toBe('ro')
        ->and(config('core.locales'))->toBe(['ro', 'en']);
});
