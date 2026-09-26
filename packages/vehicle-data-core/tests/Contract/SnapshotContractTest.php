<?php

declare(strict_types=1);

use Spectator\Spectator;
use VehicleData\Core\Database\Seeders\ExampleDataSeeder;

beforeEach(fn () => $this->seed(ExampleDataSeeder::class));

it('validates the snapshot request/response headers and status against the contract', function (): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed(['snapshot:read']);
    $this->get('/v1/snapshots/makes', bearer($key))->assertValidRequest()->assertValidResponse(200);
});

it('documents the 404 for an unknown snapshot resource', function (): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed(['snapshot:read']);
    // "unicorns" is deliberately outside the documented `resource` enum (that's the point of
    // this case), so only the response - not the request - is checked against the contract.
    $this->getJson('/v1/snapshots/unicorns', bearer($key))->assertValidResponse(404);
});

it('documents the 422 a bad updated_since produces', function (): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed(['snapshot:read']);
    $this->getJson('/v1/snapshots/makes?updated_since=not-a-date', bearer($key))->assertStatus(422)->assertValidResponse(422);
});
