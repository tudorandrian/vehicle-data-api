<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use VehicleData\Core\Models\ApiClient;
use VehicleData\Core\Models\ApiClientScope;

it('de-duplicates requested scopes before creating the client', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'test', '--owner' => 'ops', '--scopes' => 'catalogue:read,catalogue:read'])
        ->assertExitCode(0);
    expect(ApiClient::query()->sole()->scopes()->pluck('scope')->all())->toBe(['catalogue:read']);
});

it('rolls back the client if writing its scopes fails', function (): void {
    ApiClientScope::creating(function (): void {
        throw new RuntimeException('simulated scope write failure');
    });

    try {
        expect(fn () => Artisan::call('vehicle:client', ['action' => 'create', '--name' => 'test', '--owner' => 'ops']))
            ->toThrow(RuntimeException::class, 'simulated scope write failure');
        expect(ApiClient::query()->count())->toBe(0);
    } finally {
        ApiClientScope::flushEventListeners();
    }
});

it('creates a client, prints the key once and stores only the hash', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'site', '--owner' => 'ops', '--scopes' => 'catalogue:read,vin:decode', '--origins' => 'https://example.org'])
        ->expectsOutputToContain('vd_live_')->assertExitCode(0);
    $client = ApiClient::query()->firstOrFail();
    expect($client->key_hash)->toHaveLength(64)->and($client->scopes()->pluck('scope')->all())->toBe(['catalogue:read', 'vin:decode'])
        ->and($client->allowed_origins)->toBe(['https://example.org']);
});

it('trims origins and drops empty entries', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'site', '--owner' => 'ops', '--origins' => ' https://a.example , , https://b.example '])
        ->assertExitCode(0);
    $client = ApiClient::query()->firstOrFail();
    expect($client->allowed_origins)->toBe(['https://a.example', 'https://b.example']);
});

it('rejects an unknown scope', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'site', '--owner' => 'ops', '--scopes' => 'catalogue:read,not:a:scope'])
        ->assertExitCode(Command::INVALID);
    expect(ApiClient::query()->count())->toBe(0);
});

it('rejects a non-positive-integer rate or quota', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'site', '--owner' => 'ops', '--rate' => '0'])
        ->assertExitCode(Command::INVALID);
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'site', '--owner' => 'ops', '--quota' => '-5'])
        ->assertExitCode(Command::INVALID);
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'site', '--owner' => 'ops', '--rate' => '1.5'])
        ->assertExitCode(Command::INVALID);
    expect(ApiClient::query()->count())->toBe(0);
});

it('rejects rotate, revoke and show without a valid --id, without throwing', function (): void {
    $this->artisan('vehicle:client', ['action' => 'rotate'])->assertExitCode(Command::INVALID);
    $this->artisan('vehicle:client', ['action' => 'revoke', '--id' => 'nope'])->assertExitCode(Command::INVALID);
    $this->artisan('vehicle:client', ['action' => 'show', '--id' => '999999'])->assertExitCode(Command::INVALID);
});

it('show prints the plucked scope list, not the raw eager-loaded relation', function (): void {
    // `show()` prints its whole payload as a single multi-line JSON string
    // in one Output::writeln() call, so expectsOutputToContain() (which
    // only consumes one matching call per assertion) can't check several
    // substrings inside it - use Artisan::call()/output() and a plain
    // string assertion instead.
    [$client] = keyed(['catalogue:read', 'vin:decode']);
    $exitCode = Artisan::call('vehicle:client', ['action' => 'show', '--id' => $client->id]);
    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('"scopes": [')->toContain('catalogue:read')->toContain('vin:decode');
});

it('rotates and revokes', function (): void {
    [$client, $old] = keyed();
    $this->artisan('vehicle:client', ['action' => 'rotate', '--id' => $client->id])->expectsOutputToContain('vd_live_')->assertExitCode(0);
    $this->getJson('/v1/health/ready', bearer($old))->assertStatus(401);
    $this->artisan('vehicle:client', ['action' => 'revoke', '--id' => $client->id])->assertExitCode(0);
    expect($client->fresh()->disabled_at)->not->toBeNull();
    $this->artisan('vehicle:client', ['action' => 'list'])->expectsOutputToContain($client->fresh()->key_prefix)->assertExitCode(0);
});

it('rejects an unknown action', function (): void {
    $this->artisan('vehicle:client', ['action' => 'bogus'])->assertExitCode(Command::INVALID);
});

it('revokes immediately even though resolved clients are cached', function (): void {
    [$client, $key] = keyed();
    $this->getJson('/v1/health/ready', bearer($key))->assertOk();
    $this->artisan('vehicle:client', ['action' => 'revoke', '--id' => $client->id])->assertExitCode(0);
    $this->getJson('/v1/health/ready', bearer($key))->assertStatus(401);
});

it('rejects out-of-range and malformed create options with one line per problem and no database write', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => '', '--owner' => str_repeat('o', 121), '--rate' => '100000', '--quota' => '0', '--origins' => 'example.org,https://ok.example/path', '--expires' => '2020-01-01'])
        ->expectsOutputToContain('name: required, at most 80 characters')
        ->expectsOutputToContain('owner: required, at most 120 characters')
        ->expectsOutputToContain('rate: integer between 1 and 65535')
        ->expectsOutputToContain('quota: integer between 1 and 4294967295')
        ->expectsOutputToContain('origins: each must be http(s)://host[:port] with no path, and no default port (example.org, https://ok.example/path)')
        ->expectsOutputToContain('expires: a date in the future (YYYY-MM-DD)')
        ->assertExitCode(2);
    expect(ApiClient::count())->toBe(0);
});

it('lower-cases origins and accepts a port', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--origins' => 'HTTPS://Example.org:8443'])->assertExitCode(0);
    expect(ApiClient::query()->firstOrFail()->allowed_origins)->toBe(['https://example.org:8443']);
});

it('reports a malformed --expires instead of throwing, alongside other problems', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => '', '--owner' => 'ops', '--expires' => 'not-a-date'])
        ->expectsOutputToContain('name: required, at most 80 characters')
        ->expectsOutputToContain('expires: a date in the future (YYYY-MM-DD)')
        ->assertExitCode(2);
    expect(ApiClient::count())->toBe(0);
});

it('rejects a future but calendar-invalid date instead of silently rolling it over', function (): void {
    // 2030-02-30 does not exist; PHP's createFromFormat() treats the day
    // overflow as a warning, not an error, and silently rolls it over to
    // 2030-03-02 - which *is* a valid future date, so only an explicit
    // round-trip check (not the future check) can catch this. Confirmed
    // live before adding the fix: CarbonImmutable::createFromFormat('!Y-m-d',
    // '2027-02-30') returns 2027-03-02 without throwing.
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--expires' => '2030-02-30'])
        ->expectsOutputToContain('expires: a date in the future (YYYY-MM-DD)')
        ->assertExitCode(2);
    expect(ApiClient::count())->toBe(0);
});

it('stores --expires at midnight regardless of the time of day it was created', function (): void {
    $future = now()->addDays(30)->format('Y-m-d');
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--expires' => $future])->assertExitCode(0);
    $client = ApiClient::query()->firstOrFail();
    expect($client->expires_at->format('Y-m-d H:i:s'))->toBe($future.' 00:00:00');
});

it('rejects an --expires beyond the MariaDB TIMESTAMP range instead of failing in the database', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--expires' => '9999-12-31'])
        ->expectsOutputToContain('expires: must be on or before 2038-01-19')
        ->assertExitCode(2);
    expect(ApiClient::count())->toBe(0);
});

it('rejects an unknown scope with a single line, alongside other problems', function (): void {
    $exitCode = Artisan::call('vehicle:client', ['action' => 'create', '--name' => '', '--owner' => 'ops', '--scopes' => 'catalogue:read,not:a:scope']);
    expect($exitCode)->toBe(Command::INVALID);
    $output = Artisan::output();
    expect(substr_count($output, 'scopes:'))->toBe(1)
        ->and($output)->toContain('name: required, at most 80 characters')
        ->and($output)->toContain("scopes: unknown scope 'not:a:scope'");
    expect(ApiClient::query()->count())->toBe(0);
});

it('redacts userinfo in a rejected origin instead of echoing it in full', function (): void {
    $exitCode = Artisan::call('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--origins' => 'https://user:hunter2@example.com']);
    expect($exitCode)->toBe(Command::INVALID);
    $output = Artisan::output();
    expect($output)->not->toContain('hunter2')->toContain('origins:');
    expect(ApiClient::query()->count())->toBe(0);
});

it('redacts userinfo even when the rejected origin has no scheme', function (): void {
    $exitCode = Artisan::call('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--origins' => 'user:hunter2@example.org']);
    expect($exitCode)->toBe(Command::INVALID);
    $output = Artisan::output();
    expect($output)->not->toContain('hunter2')->toContain('origins:');
    expect(ApiClient::query()->count())->toBe(0);
});

it('rejects an out-of-range port and a default port for the scheme', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--origins' => 'https://example.org:443,http://example.org:80,https://example.org:99999'])
        ->expectsOutputToContain('origins:')
        ->assertExitCode(2);
    expect(ApiClient::count())->toBe(0);
});

it('de-duplicates origins after lower-casing', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--origins' => 'https://Example.org,https://example.org'])->assertExitCode(0);
    expect(ApiClient::query()->firstOrFail()->allowed_origins)->toBe(['https://example.org']);
});

it('rejects an explicitly empty --scopes instead of creating a client with no scopes', function (): void {
    $this->artisan('vehicle:client', ['action' => 'create', '--name' => 'n', '--owner' => 'o', '--scopes' => ''])
        ->expectsOutputToContain('scopes:')
        ->assertExitCode(Command::INVALID);
    expect(ApiClient::query()->count())->toBe(0);
});
