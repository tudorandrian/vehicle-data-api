<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\ExampleDataSeeder;
use VehicleData\Core\Models\Manufacturer;

// Keyed responses must never be storable by a shared cache: a CDN or proxy could otherwise
// serve them to callers without a key, bypassing authentication, rate limits and usage records.
it('sends private caching and Vary: Authorization on keyed taxonomy and snapshot responses', function (string $path, array $scopes): void {
    $this->seed(ExampleDataSeeder::class);
    [, $key] = keyed($scopes);
    $res = $this->get($path, bearer($key))->assertOk();

    $cacheControl = (string) $res->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->not->toContain('public')->not->toContain('s-maxage');
    expect(implode(', ', $res->headers->all('Vary')))->toContain('Authorization');
})->with([
    'taxonomy index' => ['/v1/taxonomies', ['catalogue:read']],
    'taxonomy' => ['/v1/taxonomies/fuel', ['catalogue:read']],
    'snapshot' => ['/v1/snapshots/makes', ['snapshot:read']],
    'list' => ['/v1/makes', ['catalogue:read']],
]);

it('names the attribution of every source present on a JSON list page', function (): void {
    $this->seed(ExampleDataSeeder::class);
    [, $key] = keyed();
    $res = $this->getJson('/v1/makes?q=Dacia', bearer($key))->assertOk();

    $res->assertHeader('X-Data-Attribution', 'Source: European Environment Agency (EEA); Contains public information under the Open Government Licence v1.0; Data from Wikidata (CC0)');
});

it('names the attribution on CSV lists and snapshots, ASCII only', function (string $path, array $scopes): void {
    $this->seed(ExampleDataSeeder::class);
    [, $key] = keyed($scopes);
    $value = (string) $this->get($path, bearer($key))->assertOk()->headers->get('X-Data-Attribution');

    expect($value)->toContain('Source: European Environment Agency (EEA)')
        ->and(preg_match('/^[\x20-\x7E]+$/', $value))->toBe(1);
})->with([
    'csv' => ['/v1/makes?format=csv', ['catalogue:read']],
    'snapshot' => ['/v1/snapshots/variants', ['snapshot:read']],
]);

it('omits the attribution header on an empty list page', function (): void {
    [, $key] = keyed();
    $res = $this->getJson('/v1/makes', bearer($key))->assertOk();

    expect($res->headers->has('X-Data-Attribution'))->toBeFalse();
});

it('exposes the attribution header to browsers', function (): void {
    $this->seed(ExampleDataSeeder::class);
    [, $key] = keyed(['catalogue:read'], ['allowed_origins' => ['https://example.org']]);
    $res = $this->getJson('/v1/makes', bearer($key) + ['Origin' => 'https://example.org'])->assertOk();

    expect((string) $res->headers->get('Access-Control-Expose-Headers'))->toContain('X-Data-Attribution');
});

it('omits the logo object when the logo has no admitted licence', function (): void {
    [, $key] = keyed();
    Manufacturer::factory()->create(['slug' => 'nolicence', 'name' => 'No Licence', 'logo_commons_file' => 'Some logo.svg', 'logo_licence' => null]);

    $json = $this->getJson('/v1/manufacturers/nolicence', bearer($key))->assertOk()->json('data');
    expect($json)->not->toHaveKey('logo');
});
