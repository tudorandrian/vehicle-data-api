<?php

declare(strict_types=1);

it('answers anonymously with status ok and no-store', function (): void {
    $this->getJson('/v1/health')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonStructure(['data' => ['status', 'version', 'time']]);
});

// The deployed version has to survive `artisan config:cache`
// (the whole point of a config-cacheable app is that config/app.php's
// `env('APP_VERSION', 'dev')` is resolved once, at cache time, and every
// runtime `config('app.version')` read afterwards returns that already-
// resolved value with no further env() lookup). Overriding config() directly
// here — rather than the APP_VERSION env var — simulates exactly that: a
// cached config carrying a baked-in value the process's actual environment
// no longer has to agree with. If HealthController ever regressed to reading
// env('APP_VERSION') directly instead of config('app.version'), this test
// would fail, because a live env() read would ignore the config() override
// below and fall back to whatever APP_VERSION happens to be set to for tests
// (or its 'dev' default).
it('reports the version from config(), so it survives config:cache', function (): void {
    config(['app.version' => 'v9.9.9-abc1234']);

    $this->getJson('/v1/health')
        ->assertOk()
        ->assertJsonPath('data.version', 'v9.9.9-abc1234');
});
