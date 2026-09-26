<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Models\ApiRequest;

it('records one row per keyed request', function (): void {
    [$client, $key] = keyed();
    $res = $this->getJson('/v1/health/ready', bearer($key));
    $row = ApiRequest::query()->firstOrFail();
    expect($row->client_id)->toBe($client->id)->and($row->route)->toBe('v1.health.ready')->and($row->status)->toBe(200)
        ->and($row->request_id)->toBe($res->headers->get('X-Request-Id'))->and($row->ip)->toBe('127.0.0.1');
});

it('records 401s without a client', function (): void {
    $this->getJson('/v1/health/ready')->assertStatus(401);
    expect(ApiRequest::query()->whereNull('client_id')->where('status', 401)->count())->toBe(1);
});

it('records "unmatched" rather than the raw path for a route with no name', function (): void {
    // A VIN-like path segment must never end up in the usage row, even for
    // a route that matched but was never given a name.
    Route::get('/v1/_unnamed-route/{segment}', fn () => response()->json(['data' => 'ok']))->middleware(['core', 'usage.record']);

    $this->getJson('/v1/_unnamed-route/RAWVINVALUE123456')->assertOk();

    $row = ApiRequest::query()->latest('id')->firstOrFail();
    expect($row->route)->toBe('unmatched')->and($row->route)->not->toContain('RAWVINVALUE123456');
});

it('does not fail the response when the usage insert itself fails', function (): void {
    // A client_id that references no row violates vd_api_requests' foreign
    // key, forcing ApiRequest::create() to throw inside terminate().
    Route::get('/v1/_ghost-client', function (Request $request) {
        $request->attributes->set('client', new ResolvedClient(999999999, 'ghost', 'ghostpfx', [], [], 60, 10000));

        return response()->json(['data' => 'ok']);
    })->middleware(['core', 'usage.record']);

    $before = ApiRequest::query()->count();
    $this->getJson('/v1/_ghost-client')->assertOk()->assertJsonPath('data', 'ok');
    // The insert failed (FK violation) and was reported, not thrown — no new row, but the response still succeeded.
    expect(ApiRequest::query()->count())->toBe($before);
});

it('sets last_used_at from the daily aggregate', function (): void {
    [$client, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $this->artisan('vehicle:usage', ['action' => 'aggregate', '--date' => 'today'])->assertExitCode(0);
    expect($client->fresh()->last_used_at)->not->toBeNull();
});

it('never moves last_used_at backwards when an older date is aggregated afterwards', function (): void {
    [$client, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $this->artisan('vehicle:usage', ['action' => 'aggregate', '--date' => 'today'])->assertExitCode(0);
    $newest = $client->fresh()->last_used_at;
    expect($newest)->not->toBeNull();

    ApiRequest::query()->create([
        'request_id' => fake()->uuid(), 'client_id' => $client->id, 'route' => 'v1.health.ready', 'method' => 'GET',
        'status' => 200, 'duration_ms' => 5, 'bytes' => 10, 'ip' => null, 'created_at' => now()->subDay(),
    ]);
    $this->artisan('vehicle:usage', ['action' => 'aggregate', '--date' => 'yesterday'])->assertExitCode(0);

    expect($client->fresh()->last_used_at->equalTo($newest))->toBeTrue();
});
