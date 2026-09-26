<?php

declare(strict_types=1);

it('allows a listed origin and varies on Authorization and Origin', function (): void {
    [, $key] = keyed(overrides: ['allowed_origins' => ['https://example.org']]);
    $res = $this->getJson('/v1/health/ready', bearer($key) + ['Origin' => 'https://example.org'])
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://example.org');
    expect($res->headers->all('Vary'))->toContain('Authorization')->toContain('Origin');
});

it('refuses an unlisted origin and any origin for server-only clients', function (): void {
    [, $key] = keyed(overrides: ['allowed_origins' => ['https://example.org']]);
    $this->getJson('/v1/health/ready', bearer($key) + ['Origin' => 'https://evil.example'])->assertStatus(403)->assertJsonPath('type', '/problems/origin-not-allowed');
    [, $k2] = keyed(overrides: ['allowed_origins' => []]);
    $this->getJson('/v1/health/ready', bearer($k2) + ['Origin' => 'https://example.org'])->assertStatus(403);
});

it('answers preflight the same way for a registered and an unregistered origin (no CORS-preflight oracle)', function (): void {
    // The preflight runs before authentication and before any per-key check, so it must never
    // reveal which origins belong to a registered client — otherwise anyone without a key could
    // probe domain names to learn who consumes this API. A syntactically valid Origin gets 204
    // whether or not any client has ever registered it, is disabled, or is expired.
    keyed(overrides: ['allowed_origins' => ['https://example.org']]);
    $registered = $this->options('/v1/makes', [], ['Origin' => 'https://example.org', 'Access-Control-Request-Method' => 'GET'])
        ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://example.org')
        ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Accept, Accept-Language, If-None-Match, X-Request-Id');
    $unregistered = $this->options('/v1/makes', [], ['Origin' => 'https://nobody.example', 'Access-Control-Request-Method' => 'GET'])
        ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://nobody.example');

    expect($registered->getStatusCode())->toBe($unregistered->getStatusCode());
});

it('still refuses the real request for an origin the preflight let through but the key does not allow', function (): void {
    // A browser never sends a key on the preflight, so the preflight cannot enforce the per-key
    // allow-list — but the real (keyed) request still must, and without an
    // Access-Control-Allow-Origin header a browser blocks that response regardless of its body.
    [, $key] = keyed(overrides: ['allowed_origins' => ['https://example.org']]);
    $this->options('/v1/makes', [], ['Origin' => 'https://unregistered.example', 'Access-Control-Request-Method' => 'GET'])
        ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://unregistered.example');

    $this->getJson('/v1/health/ready', bearer($key) + ['Origin' => 'https://unregistered.example'])
        ->assertStatus(403)->assertJsonPath('type', '/problems/origin-not-allowed')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('rejects a malformed preflight origin', function (): void {
    keyed(overrides: ['allowed_origins' => ['https://example.org']]);
    $this->options('/v1/makes', [], ['Origin' => 'not-a-valid-origin', 'Access-Control-Request-Method' => 'GET'])
        ->assertStatus(403)->assertJsonPath('type', '/problems/origin-not-allowed')
        ->assertHeader('Vary', 'Origin');
    $this->options('/v1/makes', [], ['Origin' => 'https://example.org/some/path', 'Access-Control-Request-Method' => 'GET'])->assertStatus(403);
});

it('sets Vary: Authorization and Origin on a data-group response even without an Origin header', function (): void {
    [, $key] = keyed();
    $res = $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    expect($res->headers->all('Vary'))->toContain('Authorization')->toContain('Origin');
});
