<?php

declare(strict_types=1);

it('limits per minute with Retry-After and rate headers', function (): void {
    // Away from a minute boundary: a fixed window's count must not be split
    // across two windows by a test that happens to straddle :00.
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    [, $key] = keyed(overrides: ['rate_per_minute' => 2]);
    $this->getJson('/v1/health/ready', bearer($key))->assertOk()->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $res = $this->getJson('/v1/health/ready', bearer($key));
    $res->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
    expect((int) $res->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('carries the hardening headers on a 429', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    [, $key] = keyed(overrides: ['rate_per_minute' => 1]);
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $res = $this->getJson('/v1/health/ready', bearer($key));
    $res->assertStatus(429)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    expect($res->headers->get('X-Request-Id'))->not->toBeNull();
});

it('enforces the daily quota', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    [, $key] = keyed(overrides: ['daily_quota' => 1, 'rate_per_minute' => 100]);
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $res = $this->getJson('/v1/health/ready', bearer($key));
    $res->assertStatus(429)->assertJsonPath('type', '/problems/quota-exceeded');

    $retryAfter = (string) $res->headers->get('Retry-After');
    expect($retryAfter)->toMatch('/^[0-9]+$/');
    expect((int) $retryAfter)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(86400);
});
