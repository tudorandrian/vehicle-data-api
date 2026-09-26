<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(fn () => Route::get('/v1/_needs-vin', fn () => ['data' => 'ok'])->middleware(['data', 'scope:vin:decode']));

it('returns 403 for a key without the scope', function (): void {
    [, $key] = keyed(['catalogue:read']);
    $this->getJson('/v1/_needs-vin', bearer($key))->assertStatus(403)->assertJsonPath('type', '/problems/insufficient-scope');
});

it('passes with the scope', function (): void {
    [, $key] = keyed(['vin:decode']);
    $this->getJson('/v1/_needs-vin', bearer($key))->assertOk();
});

it('throttles before checking scope, so a scope-403 still consumes the rate limit', function (): void {
    [, $key] = keyed(['catalogue:read'], ['rate_per_minute' => 1]);
    $this->getJson('/v1/_needs-vin', bearer($key))->assertStatus(403);
    $this->getJson('/v1/_needs-vin', bearer($key))->assertStatus(429);
});
