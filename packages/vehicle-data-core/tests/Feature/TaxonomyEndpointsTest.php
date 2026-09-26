<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyAlias;

beforeEach(function (): void {
    $this->seed(TaxonomySeeder::class);
    [, $this->key] = keyed();
});

it('lists taxonomies with localised names', function (): void {
    // Symfony's Request::create() defaults Accept-Language to "en-us,en;q=0.5" when no header is
    // given, and "en" is itself a supported locale, so the default-locale branch needs the header
    // cleared explicitly to be exercised (Symfony behaviour).
    $this->getJson('/v1/taxonomies', bearer($this->key) + ['Accept-Language' => ''])->assertOk()->assertHeader('Content-Language', 'ro')
        ->assertJsonFragment(['name' => 'fuel', 'label' => 'Combustibil'])->assertHeader('Cache-Control', 'max-age=0, must-revalidate, private');
});

it('returns terms in the requested language', function (): void {
    $this->getJson('/v1/taxonomies/fuel?lang=en', bearer($this->key))->assertOk()->assertHeader('Content-Language', 'en')
        ->assertJsonFragment(['code' => 'diesel', 'label' => 'Diesel']);
    $this->getJson('/v1/taxonomies/fuel', bearer($this->key) + ['Accept-Language' => 'ro'])->assertJsonFragment(['code' => 'diesel', 'label' => 'Motorină']);
});

it('exposes stable public identifiers for taxonomies and terms', function (): void {
    $summary = $this->getJson('/v1/taxonomies', bearer($this->key))->assertOk()
        ->json('data');
    $fuel = collect($summary)->firstWhere('name', 'fuel');
    expect($fuel)->toMatchArray(['name' => 'fuel'])->and($fuel['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    $taxonomy = $this->getJson('/v1/taxonomies/fuel', bearer($this->key))->assertOk()
        ->json('data');
    $diesel = collect($taxonomy['terms'])->firstWhere('code', 'diesel');
    expect($taxonomy)->toMatchArray(['name' => 'fuel', 'id' => $fuel['id']])
        ->and($diesel)->toMatchArray(['code' => 'diesel'])->and($diesel['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    $this->getJson('/v1/taxonomies/'.$fuel['id'], bearer($this->key))->assertOk()->assertJsonPath('data.id', $fuel['id']);
});

it('redirects a retired taxonomy name to its current name without losing the query string', function (): void {
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    TaxonomyAlias::query()->create(['taxonomy_id' => $fuel->id, 'name' => 'energy']);
    $this->get('/v1/taxonomies/energy?lang=en', bearer($this->key))->assertRedirect('/v1/taxonomies/fuel?lang=en')
        ->assertHeader('Cache-Control', 'max-age=86400, private');
});

it('404s for an unknown taxonomy and 422s for an unknown lang', function (): void {
    $this->getJson('/v1/taxonomies/nope', bearer($this->key))->assertStatus(404);
    $this->getJson('/v1/taxonomies/fuel?lang=de', bearer($this->key))->assertStatus(422)->assertJsonPath('errors.0.field', 'lang');
});

it('requires the catalogue scope', function (): void {
    [, $k] = keyed(['vin:decode']);
    $this->getJson('/v1/taxonomies', bearer($k))->assertStatus(403);
});
