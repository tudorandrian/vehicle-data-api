<?php

declare(strict_types=1);

use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\Source;

beforeEach(fn () => [, $this->key] = keyed());

it('paginates, sorts and searches by prefix', function (): void {
    Manufacturer::factory()->create(['name' => 'Dacia', 'slug' => 'dacia', 'founded_year' => 1966]);
    Manufacturer::factory()->create(['name' => 'Renault', 'slug' => 'renault', 'founded_year' => 1899]);
    Manufacturer::factory()->create(['name' => 'Daewoo', 'slug' => 'daewoo', 'founded_year' => 1967]);
    $this->getJson('/v1/manufacturers?q=da&sort=-founded_year&per_page=1', bearer($this->key))->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.slug', 'daewoo')
        ->assertJsonPath('links.next', fn ($v) => str_contains((string) $v, 'page=2'))->assertJsonPath('links.prev', null);
});

it('keeps class-2 keys with null and omits class-3 keys', function (): void {
    Manufacturer::factory()->create(['slug' => 'x', 'name' => 'X', 'country_code' => null, 'founded_year' => null, 'website' => null, 'logo_commons_file' => null]);
    $json = $this->getJson('/v1/manufacturers/x', bearer($this->key))->assertOk()->json('data');
    expect($json)->toHaveKeys(['slug', 'name', 'country_code', 'founded_year', 'parent', 'website'])->not->toHaveKey('logo')
        ->and($json['country_code'])->toBeNull();
});

it('exposes the logo as a class-3 object linking to the Commons file page, never the image', function (): void {
    Manufacturer::factory()->create(['slug' => 'honda', 'name' => 'Honda', 'logo_commons_file' => 'Honda logo.svg', 'logo_licence' => 'Public domain']);
    $this->getJson('/v1/manufacturers/honda', bearer($this->key))->assertOk()
        ->assertJsonPath('data.logo.commons_file', 'Honda logo.svg')->assertJsonPath('data.logo.url', 'https://commons.wikimedia.org/wiki/File:Honda%20logo.svg');
});

it('supports sparse fieldsets and updated_since', function (): void {
    $old = Manufacturer::factory()->create(['slug' => 'old', 'name' => 'Old']);
    $old->forceFill(['updated_at' => '2020-01-01 00:00:00'])->saveQuietly();
    Manufacturer::factory()->create(['slug' => 'new', 'name' => 'New']);
    $res = $this->getJson('/v1/manufacturers?fields=website&updated_since=2025-01-01T00:00:00Z', bearer($this->key))->assertOk();
    expect($res->json('data'))->toHaveCount(1)->and($res->json('data.0'))->toHaveKeys(['slug', 'name', 'website'])->not->toHaveKey('country_code');
});

it('validates parameters as problem+json 422', function (): void {
    $this->getJson('/v1/manufacturers?per_page=1000&sort=hacker', bearer($this->key))->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')->assertJsonCount(2, 'errors');
});

it('lists sources on the detail response', function (): void {
    $m = Manufacturer::factory()->create(['slug' => 'ford', 'name' => 'Ford']);
    $src = Source::query()->create(['key' => 'wikidata', 'name' => 'Wikidata', 'licence_id' => 'CC0-1.0', 'licence_name' => 'CC0 1.0', 'licence_url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'attribution' => 'Wikidata', 'url' => 'https://www.wikidata.org/']);
    $m->sources()->create(['source_id' => $src->id, 'source_ref' => 'Q44294', 'retrieved_at' => now(), 'checksum' => str_repeat('0', 64)]);
    $this->getJson('/v1/manufacturers/ford', bearer($this->key))->assertOk()->assertJsonPath('sources.0.key', 'wikidata')->assertJsonPath('sources.0.licence', 'CC0-1.0');
});
