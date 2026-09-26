<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use VehicleData\Core\Auth\ApiKey;

it('reuses cached client metadata but checks credential validity on the second request', function (): void {
    // The array cache store used elsewhere in tests never serializes, so it would
    // never catch a ResolvedClient coming back as __PHP_Incomplete_Class under
    // serializable_classes=false (config/cache.php). Switch to the real store.
    config(['cache.default' => 'database']);
    expect(Schema::hasTable(config('cache.stores.database.table', 'cache')))->toBeTrue();

    [, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();

    $queriedClients = false;
    DB::listen(function ($query) use (&$queriedClients): void {
        if (str_contains($query->sql, 'vd_api_clients')) {
            $queriedClients = true;
        }
    });
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    expect($queriedClients)->toBeTrue();
});

it('rejects a stale cache entry restored after credential invalidation', function (string $action): void {
    config(['cache.default' => 'database']);
    [$client, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $cacheKey = 'client:'.ApiKey::hash($key);
    $stale = Cache::get($cacheKey);
    expect($stale)->toBeArray();

    $this->artisan('vehicle:client', ['action' => $action, '--id' => $client->id])->assertExitCode(0);
    // A resolver that read the old row before the command can finish its cache
    // write after both invalidations. Reproduce that interleaving deterministically.
    Cache::put($cacheKey, $stale, 60);
    $this->getJson('/v1/health/ready', bearer($key))->assertUnauthorized();
    expect(Cache::get($cacheKey))->toBeNull();
})->with(['revoke', 'rotate']);

it('never caches a failed lookup', function (): void {
    config(['cache.default' => 'database']);
    $this->getJson('/v1/health/ready', bearer('vd_live_'.str_repeat('a', 40)))->assertStatus(401);
    $rows = DB::table(config('cache.stores.database.table', 'cache'))->where('key', 'like', '%client:%')->count();
    expect($rows)->toBe(0);
});

it('does not let a resolved client outlive its own expiry inside the cache', function (): void {
    config(['cache.default' => 'database']);
    $this->travelTo(now());
    [$client, $key] = keyed(overrides: ['expires_at' => now()->addSeconds(10)]);
    $this->getJson('/v1/health/ready', bearer($key))->assertOk(); // warms the cache with a ~10s TTL, not the default 60s
    $this->travelTo(now()->addSeconds(15));
    $this->getJson('/v1/health/ready', bearer($key))->assertStatus(401);
});

it('falls through to a fresh lookup instead of trusting a non-array cache value', function (): void {
    // Nothing in this codebase should ever put a non-array under a client:<hash> key, but
    // is_array() guards it anyway rather than trusting the @var annotation on faith — a
    // foreign/stale value under that key must not reach fromArray() and blow up the request.
    [, $key] = keyed();
    Cache::put('client:'.ApiKey::hash($key), 'not-an-array-of-client-fields', 60);
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
});
