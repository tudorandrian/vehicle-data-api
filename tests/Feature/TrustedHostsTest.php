<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

// Laravel applies trusted hosts only outside the `local` and `testing` environments, so each test
// switches to `production` and restores it; the trusted-host patterns are process-wide
// (Symfony's Request keeps them statically), so they are cleared after every test.
beforeEach(function (): void {
    config(['app.url' => 'https://api.example.test']);
    app()->instance('env', 'production');
});

afterEach(function (): void {
    app()->instance('env', 'testing');
    Request::setTrustedHosts([]);
});

it('rejects a forged Host header instead of reflecting it into the served contract', function (): void {
    $response = $this->get('http://forged.example.org/openapi.yaml');

    expect($response->getStatusCode())->toBe(400)
        ->and((string) $response->getContent())->not->toContain('forged.example.org');
});

it('serves the contract with the configured host as servers[0].url', function (): void {
    $response = $this->get('https://api.example.test/openapi.yaml')->assertOk();

    expect(Yaml::parse((string) $response->getContent())['servers'][0]['url'])->toBe('https://api.example.test');
});

it('trusts only the configured host, not its subdomains', function (): void {
    expect($this->get('https://other.api.example.test/v1/health')->getStatusCode())->toBe(400)
        ->and($this->get('https://api.example.test/v1/health')->getStatusCode())->toBe(200);
});
