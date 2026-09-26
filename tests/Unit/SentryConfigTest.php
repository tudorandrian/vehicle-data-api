<?php

declare(strict_types=1);

it('is disabled without a DSN and never sends default PII', function (): void {
    // env('SENTRY_LARAVEL_DSN') is an empty string, not null, when the .env
    // key is present but blank — empty() tolerates both, which is the
    // actual "disabled" signal the Sentry SDK itself checks.
    expect(empty(config('sentry.dsn')))->toBeTrue()
        ->and(config('sentry.send_default_pii'))->toBeFalse()
        ->and((float) config('sentry.traces_sample_rate'))->toBe(0.1);
});
