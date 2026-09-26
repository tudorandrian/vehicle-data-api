<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\Yaml\Yaml;
use VehicleData\Core\Database\Seeders\SourceSeeder;
use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\Variant;

/**
 * The documented response object for a GET operation and status, with a
 * `#/components/responses/*` reference resolved.
 *
 * @return array<string, mixed>
 */
function documentedResponse(string $path, int $status): array
{
    /** @var array<string, mixed> $doc */
    $doc = Yaml::parseFile(base_path('packages/vehicle-data-core/resources/openapi/openapi.yaml'));
    $response = $doc['paths'][$path]['get']['responses'][$status];
    if (isset($response['$ref'])) {
        $response = $doc['components']['responses'][Str::afterLast($response['$ref'], '/')];
    }

    return $response;
}

/** Every header the contract documents for this response must actually be sent. */
function assertDocumentedHeaders(TestResponse $res, string $path, int $status): void
{
    $headers = array_keys(documentedResponse($path, $status)['headers'] ?? []);
    expect($headers)->not->toBe([]);
    foreach ($headers as $header) {
        expect($res->headers->has($header))->toBeTrue("{$path} {$status} documents {$header} but does not send it");
    }
}

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->seed(TaxonomySeeder::class);
    [, $this->key] = keyed();
    $man = Manufacturer::factory()->create(['slug' => 'renault', 'name' => 'Renault', 'logo_commons_file' => 'Renault 2021 Text.svg', 'logo_licence' => 'Public domain']);
    $make = Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia', 'manufacturer_id' => $man->id, 'ro_fleet_count' => 1, 'ro_fleet_year' => 2025]);
    $model = $make->models()->create(['slug' => 'dacia-duster', 'name' => 'Duster']);
    Variant::factory()->create(['model_id' => $model->id, 'public_id' => str_repeat('7', 26), 'specifications' => ['doors' => 5, 'fuel_mode' => 'M']]);
    // Every class-2 value null: the contract must accept null, not only absent-or-typed.
    Variant::factory()->create(['model_id' => $model->id, 'euro_norm_code' => null, 'engine_cc' => null, 'power_kw' => null, 'mass_kg' => null, 'co2_wltp' => null, 'year_from' => null]);
});

it('validates every catalogue route against the contract', function (string $path): void {
    $this->getJson($path, bearer($this->key))->assertValidRequest()->assertValidResponse(200);
})->with([
    '/v1/health', '/v1/health/ready', '/openapi.yaml', '/v1/taxonomies', '/v1/taxonomies/fuel?lang=en',
    '/v1/manufacturers?q=re&sort=-founded_year', '/v1/manufacturers/renault',
    '/v1/makes', '/v1/makes?kind=car&manufacturer=renault', '/v1/makes/dacia', '/v1/makes/dacia/models', '/v1/models/dacia-duster',
    '/v1/models/dacia-duster/variants', '/v1/models/dacia-duster/variants?fuel=petrol&power_kw_min=1', '/v1/variants/'.str_repeat('7', 26),
]);

it('validates the CSV representation of every list route and its documented header row', function (string $path, string $specPath): void {
    $res = $this->get($path.'?format=csv', bearer($this->key))->assertValidRequest()->assertValidResponse(200)
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $documented = Str::afterLast(documentedResponse($specPath, 200)['content']['text/csv']['schema']['description'], ': ');
    $header = strtok(substr($res->streamedContent(), 3), "\n");
    expect(trim((string) $header))->toBe($documented);
})->with([
    ['/v1/manufacturers', '/v1/manufacturers'], ['/v1/makes', '/v1/makes'],
    ['/v1/makes/dacia/models', '/v1/makes/{key}/models'], ['/v1/models/dacia-duster/variants', '/v1/models/{key}/variants'],
]);

it('sends every header the contract documents', function (): void {
    // X-Data-Attribution is sent only when a record on the page has provenance.
    $this->seed(SourceSeeder::class);
    RecordSource::query()->create(['record_type' => 'make', 'record_id' => Make::query()->where('slug', 'dacia')->value('id'),
        'source_id' => Source::query()->where('key', 'eea')->value('id'), 'source_ref' => 'contract', 'retrieved_at' => now(), 'checksum' => str_repeat('0', 64)]);
    assertDocumentedHeaders($this->getJson('/v1/makes', bearer($this->key))->assertOk(), '/v1/makes', 200);
    assertDocumentedHeaders($this->getJson('/v1/makes/dacia', bearer($this->key))->assertOk(), '/v1/makes/{key}', 200);
    assertDocumentedHeaders($this->getJson('/v1/taxonomies', bearer($this->key))->assertOk(), '/v1/taxonomies', 200);
    assertDocumentedHeaders($this->get('/openapi.yaml')->assertOk(), '/openapi.yaml', 200);
    assertDocumentedHeaders($this->getJson('/v1/makes')->assertStatus(401), '/v1/makes', 401);
    expect(implode(', ', $this->getJson('/v1/makes', bearer($this->key))->headers->all('Vary')))->toContain('Accept-Language')->toContain('Authorization')->toContain('Origin');
});

it('validates a 304 revalidation against the contract', function (string $path): void {
    $etag = (string) $this->getJson($path, bearer($this->key))->assertOk()->headers->get('ETag');
    $this->getJson($path, bearer($this->key) + ['If-None-Match' => $etag])->assertStatus(304)->assertValidResponse(304);
})->with(['/v1/taxonomies', '/v1/taxonomies/fuel', '/v1/manufacturers', '/v1/makes/dacia', '/v1/models/dacia-duster/variants', '/openapi.yaml']);

it('validates the error responses', function (): void {
    $this->getJson('/v1/makes/none', bearer($this->key))->assertValidResponse(404);
    $this->getJson('/v1/makes?per_page=0', bearer($this->key))->assertValidResponse(422);
    $this->getJson('/v1/makes?kind=foo', bearer($this->key))->assertValidResponse(422);
    $this->getJson('/v1/makes')->assertValidResponse(401);
    $this->get('/v1/makes/dacia', bearer($this->key) + ['Accept' => 'text/csv'])->assertValidResponse(406);
    $this->getJson('/v1/makes', bearer($this->key) + ['Origin' => 'https://not-allowed.example'])->assertValidResponse(403);
    [, $k] = keyed(['vin:decode']);
    $this->getJson('/v1/makes', bearer($k))->assertValidResponse(403)->assertJsonPath('required_scope', 'catalogue:read');
    $this->getJson('/v1/makes', bearer($this->key) + ['Origin' => 'https://not-allowed.example'])->assertJsonPath('origin', 'https://not-allowed.example');
    $this->call('GET', '/v1/makes', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->key, 'CONTENT_LENGTH' => '999999999'])->assertValidResponse(413);
});

it('validates the rate-limit, quota and failed-authentication 429 problems', function (): void {
    [, $limited] = keyed(['catalogue:read'], ['rate_per_minute' => 1]);
    $this->getJson('/v1/makes', bearer($limited))->assertOk();
    $this->getJson('/v1/makes', bearer($limited))->assertValidResponse(429)->assertHeader('Retry-After');

    [, $quota] = keyed(['catalogue:read'], ['daily_quota' => 1]);
    $this->getJson('/v1/manufacturers', bearer($quota))->assertOk();
    $this->getJson('/v1/manufacturers', bearer($quota))->assertValidResponse(429)->assertJsonPath('type', '/problems/quota-exceeded');

    config(['core.auth_fail_per_minute' => 1]);
    $this->getJson('/v1/taxonomies', bearer('vd_live_wrong'))->assertStatus(401);
    $this->getJson('/v1/taxonomies', bearer('vd_live_wrong'))->assertValidResponse(429)->assertHeader('Retry-After')
        ->assertJsonPath('detail', 'Too many failed authentication attempts.');
});

it('documents the 422 an unsupported lang produces on every route that accepts lang', function (string $path): void {
    $this->getJson($path.'?lang=de', bearer($this->key))->assertStatus(422)->assertJsonPath('errors.0.field', 'lang')->assertValidResponse(422);
})->with([
    '/v1/taxonomies', '/v1/taxonomies/fuel', '/v1/manufacturers', '/v1/manufacturers/renault', '/v1/makes', '/v1/makes/dacia',
    '/v1/makes/dacia/models', '/v1/models/dacia-duster', '/v1/models/dacia-duster/variants', '/v1/variants/'.str_repeat('7', 26),
]);

it('documents 301 for a retired slug', function (): void {
    $make = Make::factory()->create(['slug' => 'volkswagen']);
    SlugAlias::query()->create(['record_type' => $make->getMorphClass(), 'record_id' => $make->id, 'slug' => 'vw']);
    $this->get('/v1/makes/vw', bearer($this->key))->assertValidRequest()->assertValidResponse(301);
});
