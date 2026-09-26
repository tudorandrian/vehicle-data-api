<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use VehicleData\Core\Auth\Counters;

it('rejects a missing key with 401 problem+json', function (): void {
    $this->getJson('/v1/health/ready')->assertStatus(401)->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', '/problems/unauthenticated');
});

it('rejects an unknown, a disabled and an expired key', function (): void {
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('x', 40)))->assertStatus(401);
    [$c, $k] = keyed(overrides: ['disabled_at' => now()]);
    $this->getJson('/v1/health/ready', bearer($k))->assertStatus(401);
    [$c2, $k2] = keyed(overrides: ['expires_at' => now()->subDay()]);
    $this->getJson('/v1/health/ready', bearer($k2))->assertStatus(401);
});

it('accepts a valid key and exposes the client on the request', function (): void {
    [$client, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    // last_used_at is no longer written per-request (that cost a write on every keyed
    // call); it is set from the daily usage aggregate instead - see UsageRecordingTest.
    expect($client->fresh()->last_used_at)->toBeNull();
});

it('counts failed attempts per ip and logs only the prefix', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    Log::spy();
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('a', 40)))->assertStatus(401);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => $ctx['key_prefix'] === 'aaaaaaaa' && ! str_contains(json_encode($ctx), str_repeat('a', 40)))->once();
    expect(DB::table('vd_api_counters')->where('scope', 'ip:127.0.0.1')->where('window', Counters::minuteWindow()[0])->value('count'))->toBe(1);
});

it('never logs the caller IP on an authentication failure (it stays only in vd_api_requests, purged per retention)', function (): void {
    Log::spy();
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('a', 40)))->assertStatus(401);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => ! array_key_exists('ip', $ctx))->once();
});

it('logs a null key_prefix for a malformed bearer token, never a partial secret', function (): void {
    Log::spy();
    $this->getJson('/v1/health/ready', bearer('not-a-real-key'))->assertStatus(401);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => $ctx['key_prefix'] === null)->once();
});

it('throttles repeated authentication failures from the same ip with a 429', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    config(['core.auth_fail_per_minute' => 2]);
    $this->getJson('/v1/health/ready')->assertStatus(401);
    $this->getJson('/v1/health/ready')->assertStatus(401);
    $res = $this->getJson('/v1/health/ready');
    $res->assertStatus(429)->assertJsonPath('type', '/problems/rate-limited')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    expect($res->headers->get('X-Request-Id'))->not->toBeNull();
    expect((int) $res->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('carries the hardening headers and the request id on a 401', function (): void {
    $response = $this->getJson('/v1/health/ready');
    $response->assertStatus(401)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    expect($response->headers->get('X-Request-Id'))->not->toBeNull();
});

it('still logs the attacked key prefix once an ip is throttled, instead of going silent behind the 429', function (): void {
    // The early 429 return used to skip Log::warning('api.auth.failed') entirely: a brute
    // force against one key prefix would show a flat ceiling of `auth_fail_per_minute` log
    // lines and then nothing, even though every further attempt still cost a counter hit.
    // Three requests, not two: with only one blocked attempt in the window, ->once() would
    // pass whether api.auth.blocked fires on every blocked attempt (the bug) or only on the
    // one that first crosses the limit (the fix) - a second blocked attempt is needed to tell
    // "logged once per window" apart from "logged once because we only tried once".
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    config(['core.auth_fail_per_minute' => 1]);
    Log::spy();

    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('c', 40)))->assertStatus(401);
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('c', 40)))->assertStatus(429);
    $res = $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('c', 40)));
    $res->assertStatus(429);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx) => $m === 'api.auth.blocked' && $ctx['key_prefix'] === 'cccccccc')->once();
});

it('never blocks a valid key from an ip that exceeded the failure limit', function (): void {
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    config(['core.auth_fail_per_minute' => 2]);
    [, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('b', 40)))->assertStatus(401);
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('b', 40)))->assertStatus(401);
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('b', 40)))->assertStatus(429);
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    // Hit-then-compare: the 3rd (refused) attempt still increments the counter before the
    // over-limit check trips, so the stored count is 3, not the 2 attempts that got a 401.
    expect(DB::table('vd_api_counters')->where('scope', 'ip:127.0.0.1')->where('window', Counters::minuteWindow()[0])->value('count'))->toBe(3);
    $this->getJson('/v1/health/ready')->assertStatus(429);
    $this->getJson('/v1/health/ready', bearer('not-a-real-key'))->assertStatus(429);
});
