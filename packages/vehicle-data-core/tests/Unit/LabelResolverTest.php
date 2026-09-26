<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use VehicleData\Core\Locale\LabelResolver;

it('prefers ?lang, then Accept-Language, then the default', function (): void {
    $r = new LabelResolver(['ro', 'en'], 'ro');
    expect($r->resolve(Request::create('/x', 'GET', ['lang' => 'en'])))->toBe('en')
        ->and($r->resolve(Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,en;q=0.8,ro;q=0.5'])))->toBe('en')
        ->and($r->resolve(Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr'])))->toBe('ro')
        // Symfony's Request::create() defaults Accept-Language to "en-us,en;q=0.5" when the
        // server array doesn't set it, so an explicit empty header is needed to exercise the
        // "no Accept-Language at all" branch (Symfony behaviour, not implementation).
        ->and($r->resolve(Request::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => ''])))->toBe('ro');
});

it('rejects an unsupported ?lang with 422', function (): void {
    expect(fn () => (new LabelResolver(['ro', 'en'], 'ro'))->resolve(Request::create('/x', 'GET', ['lang' => 'fr'])))->toThrow(ValidationException::class);
});
