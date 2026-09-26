<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\ExampleDataSeeder;
use VehicleData\Core\Http\Controllers\SnapshotController;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\VehicleModel;

beforeEach(fn () => $this->seed(ExampleDataSeeder::class));

it('streams a gzip artifact of JSON Lines of a whole resource, never Content-Encoding', function (): void {
    [, $key] = keyed(['snapshot:read']);
    $res = $this->get('/v1/snapshots/models', bearer($key));
    // application/gzip + no Content-Encoding: the body is a downloadable gzip file, not a
    // transport-encoded representation a proxy might transparently decompress.
    $res->assertOk()->assertHeader('Content-Type', 'application/gzip')->assertHeader('Cache-Control', 'max-age=0, private');
    expect($res->headers->has('Content-Encoding'))->toBeFalse();
    $lines = array_filter(explode("\n", (string) gzdecode($res->streamedContent())));
    expect(count($lines))->toBe(VehicleModel::count());
    $first = json_decode($lines[0], true);
    expect($first)->toHaveKeys(['id', 'slug', 'name', 'make', 'first_year', 'last_year'])
        ->and(array_key_first($first))->toBe('id');
});

it('emits id as the first key on every resource snapshot', function (string $resource): void {
    [, $key] = keyed(['snapshot:read']);
    $res = $this->get('/v1/snapshots/'.$resource, bearer($key))->assertOk();
    $lines = array_filter(explode("\n", (string) gzdecode($res->streamedContent())));
    expect($lines)->not->toBe([]);
    $first = json_decode($lines[0], true);
    expect(array_key_first($first))->toBe('id');
})->with(['manufacturers', 'makes', 'models', 'variants']);

it('carries licence and attribution-document headers with the bulk download', function (): void {
    [, $key] = keyed(['snapshot:read']);
    $res = $this->get('/v1/snapshots/models', bearer($key))->assertOk();
    $expected = Source::query()
        ->whereIn('id', RecordSource::query()->where('record_type', 'model')->select('source_id')->distinct())
        ->orderBy('licence_id')->pluck('licence_id')->unique()->values()->all();
    expect($expected)->not->toBe([]);
    $res->assertHeader('X-Data-Licences', implode(',', $expected));
    $res->assertHeader('Link', '<'.SnapshotController::LICENSE_DOC_URL.'>; rel="license"');
});

it('filters with updated_since and refuses unknown resources and missing scope', function (): void {
    [, $key] = keyed(['snapshot:read']);
    $res = $this->get('/v1/snapshots/makes?updated_since='.rawurlencode(now()->addDay()->toIso8601String()), bearer($key))->assertOk();
    expect(trim((string) gzdecode($res->streamedContent())))->toBe('');
    $this->getJson('/v1/snapshots/unicorns', bearer($key))->assertStatus(404);
    [, $cat] = keyed(['catalogue:read']);
    $this->getJson('/v1/snapshots/makes', bearer($cat))->assertStatus(403);
});

it('rejects a malformed updated_since with a 422 problem, never a 500', function (): void {
    [, $key] = keyed(['snapshot:read']);
    $this->getJson('/v1/snapshots/makes?updated_since=not-a-date', bearer($key))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'updated_since');
});
