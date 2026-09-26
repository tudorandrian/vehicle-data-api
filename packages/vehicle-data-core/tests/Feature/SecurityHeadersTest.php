<?php

declare(strict_types=1);

it('sets the hardening headers on every /v1 response', function (): void {
    $this->getJson('/v1/health')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
});
