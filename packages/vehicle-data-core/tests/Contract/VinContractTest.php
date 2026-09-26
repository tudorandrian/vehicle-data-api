<?php

declare(strict_types=1);
use Spectator\Spectator;

it('matches the contract for a decode without a WMI match', function (): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed(['vin:decode']);
    $this->getJson('/v1/vin/1HGCM82633A004352', bearer($key))->assertValidRequest()->assertValidResponse(200);
    $this->getJson('/v1/vin/12345678901234567', bearer($key))->assertValidRequest()->assertValidResponse(200);
});

// The malformed-VIN case sends a genuinely malformed VIN and asserts the
// documented 400 problem, distinct from the 200 case above.
it('matches the contract for a malformed VIN', function (): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed(['vin:decode']);
    $this->getJson('/v1/vin/NOTAVIN', bearer($key))->assertValidResponse(400);
});

it('documents the 422 an unsupported lang produces', function (): void {
    Spectator::using('openapi.yaml');
    [, $key] = keyed(['vin:decode']);
    $this->getJson('/v1/vin/1HGCM82633A004352?lang=de', bearer($key))->assertStatus(422)->assertJsonPath('errors.0.field', 'lang')->assertValidResponse(422);
});
