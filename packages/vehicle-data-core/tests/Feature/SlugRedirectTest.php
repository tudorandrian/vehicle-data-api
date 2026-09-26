<?php

declare(strict_types=1);

use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\VehicleModel;

beforeEach(fn () => [, $this->key] = keyed());

it('redirects a retired make slug to the canonical url on plain and nested routes, keeping the query string', function (): void {
    $make = Make::factory()->create(['slug' => 'volkswagen', 'name' => 'Volkswagen']);
    SlugAlias::query()->create(['record_type' => $make->getMorphClass(), 'record_id' => $make->id, 'slug' => 'vw']);

    $res = $this->getJson('/v1/makes/vw?lang=en', bearer($this->key))->assertStatus(301)
        ->assertHeader('Location', url('/v1/makes/volkswagen?lang=en'))
        ->assertHeader('Cache-Control', 'max-age=86400, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($res->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
    $this->getJson('/v1/makes/vw/models?per_page=5', bearer($this->key))->assertStatus(301)->assertHeader('Location', url('/v1/makes/volkswagen/models?per_page=5'));
    $this->call('HEAD', '/v1/makes/vw', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->key])->assertStatus(301);
});

it('requires the catalogue:read scope before the redirect: a key without it gets 403, not 301', function (): void {
    $make = Make::factory()->create(['slug' => 'volkswagen', 'name' => 'Volkswagen']);
    SlugAlias::query()->create(['record_type' => $make->getMorphClass(), 'record_id' => $make->id, 'slug' => 'vw']);
    [, $wrongScope] = keyed(['vin:decode']);

    $this->getJson('/v1/makes/vw', bearer($wrongScope))->assertStatus(403)->assertJsonPath('required_scope', 'catalogue:read');
});

it('serves a make by id and 404s an unknown id or slug', function (): void {
    $make = Make::factory()->create(['slug' => 'dacia']);
    $this->getJson('/v1/makes/'.$make->public_id, bearer($this->key))->assertOk()->assertJsonPath('data.id', $make->public_id)->assertJsonPath('data.slug', 'dacia');
    $this->getJson('/v1/makes/01J8ZQ3W7S5K4M2N9P6R8T1V0Y', bearer($this->key))->assertStatus(404)->assertJsonPath('type', '/problems/not-found');
    $this->getJson('/v1/makes/unknown', bearer($this->key))->assertStatus(404);
});

it('redirects a retired model slug and requires a key for the redirect', function (): void {
    $model = VehicleModel::factory()->create(['slug' => 'volkswagen-golf']);
    SlugAlias::query()->create(['record_type' => $model->getMorphClass(), 'record_id' => $model->id, 'slug' => 'vw-golf']);
    $this->getJson('/v1/models/vw-golf/variants', bearer($this->key))->assertStatus(301)->assertHeader('Location', url('/v1/models/volkswagen-golf/variants'));
    $this->getJson('/v1/models/vw-golf')->assertStatus(401);
});
