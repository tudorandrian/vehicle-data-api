<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Spectator\Spectator;

it('reports a failed cache probe as degraded readiness', function (bool $throws): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();

    $cache = Mockery::mock(Cache::getFacadeRoot())->makePartial();
    Cache::swap($cache);
    if ($throws) {
        $cache->shouldReceive('put')->with('health-probe', 1, 10)->once()->andThrow(new RuntimeException('cache unavailable'));
    } else {
        $cache->shouldReceive('put')->with('health-probe', 1, 10)->once()->andReturnTrue();
        $cache->shouldReceive('get')->with('health-probe')->once()->andReturnNull();
    }

    $this->getJson('/v1/health/ready', bearer($key))->assertStatus(503)
        ->assertJsonPath('data.status', 'degraded')->assertJsonPath('data.checks.cache', 'fail')
        ->assertValidResponse(503);
})->with([false, true]);
