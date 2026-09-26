<?php

declare(strict_types=1);

use VehicleData\Core\Providers\CoreServiceProvider;

it('refuses to boot with APP_DEBUG=true in production', function (): void {
    app()->instance('env', 'production');
    config()->set('app.debug', true);
    expect(fn () => (new CoreServiceProvider(app()))->boot())->toThrow(RuntimeException::class, 'APP_DEBUG');
});
