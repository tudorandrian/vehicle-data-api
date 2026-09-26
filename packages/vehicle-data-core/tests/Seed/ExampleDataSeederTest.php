<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\ExampleDataSeeder;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyLabel;
use VehicleData\Core\Models\TaxonomyTerm;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Reports\ExampleCoverage;
use VehicleData\Core\Support\PublicId;
use VehicleData\Core\Support\PublicIdFormat;

beforeEach(fn () => $this->seed(ExampleDataSeeder::class));

it('gives the sourced taxonomies at least one or two distinct real examples per term where the fixtures allow', function (): void {
    $cov = ExampleCoverage::compute();
    foreach (['petrol', 'diesel', 'electric', 'petrol_hybrid'] as $fuel) {
        expect(count($cov['fuel'][$fuel]))->toBeGreaterThanOrEqual(2, "fuel={$fuel}");
        expect(count(array_unique(array_map(fn ($e) => explode(' ', $e['name'])[0], $cov['fuel'][$fuel]))))->toBe(count($cov['fuel'][$fuel]));
    }
    expect(count($cov['eu_category']['m1']))->toBe(2)->and(count($cov['euro_norm']['euro_6d']))->toBeGreaterThanOrEqual(1)->and(count($cov['euro_norm']['euro_6e']))->toBeGreaterThanOrEqual(1)
        ->and(count($cov['national_category']['autoturism']))->toBe(2);
});

it('backs every seeded example with a provenance row and keeps codes valid', function (): void {
    Variant::query()->each(fn (Variant $v) => expect(RecordSource::query()->where('record_type', 'variant')->where('record_id', $v->id)->exists())->toBeTrue());
    Make::query()->whereNotNull('ro_fleet_count')->each(fn (Make $m) => expect(RecordSource::query()->where('record_type', 'make')->where('record_id', $m->id)->exists())->toBeTrue());
    TaxonomyTerm::query()->each(fn (TaxonomyTerm $t) => expect($t->code)->toMatch('/^[a-z0-9_]+$/'));
    expect(TaxonomyLabel::count())->toBe(TaxonomyTerm::count() * 2);
});

it('respects a configured wmi reject-share threshold instead of always tolerating every rejection', function (): void {
    // The vpic_wmi fixture's real reject share (6-character WMIs, rejected as `wmi_length`) is
    // ~9%: a threshold tighter than that must abort the wmi import, proving the seeder reads
    // core.import_reject_share_wmi rather than hardcoding a permissive 1.0.
    config(['core.import_reject_share_wmi' => 0.05]);
    $this->seed(ExampleDataSeeder::class);
})->throws(RuntimeException::class, 'Aborted');

it('is idempotent', function (): void {
    $counts = fn () => [Variant::count(), Make::count(), RecordSource::count()];
    $before = $counts();
    $this->seed(ExampleDataSeeder::class);
    expect($counts())->toBe($before);
});

it('mints the first id of every seeded record deterministically from its provenance ref', function (): void {
    // ExampleDataSeeder runs eea before ro-fleet/wikidata/wmi (fixed order, not filesystem or
    // hash order), so "DACIA" is minted by eea with its raw Mk value, unquoted and untrimmed
    // beyond EeaSource's own trim(); BMW's manufacturer id is minted by wikidata off its QID.
    $dacia = Make::query()->where('slug', 'dacia')->firstOrFail();
    $duster = VehicleModel::query()->where('slug', 'dacia-duster')->firstOrFail();
    $bmw = Manufacturer::query()->where('slug', 'bmw')->firstOrFail();
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    $diesel = TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->firstOrFail();

    expect($dacia->public_id)->toBe(PublicId::for('make|eea|DACIA'))
        ->and($duster->public_id)->toBe(PublicId::for('model|eea|'.$dacia->public_id.'|DUSTER'))
        ->and($bmw->public_id)->toBe(PublicId::for('manufacturer|wikidata|Q26678'))
        ->and($fuel->public_id)->toBe(PublicId::for('taxonomy|fuel'))
        ->and($diesel->public_id)->toBe(PublicId::for('term|fuel|diesel'));

    foreach ([$dacia->public_id, $duster->public_id, $bmw->public_id, $fuel->public_id, $diesel->public_id] as $id) {
        expect($id)->toMatch(PublicIdFormat::PATTERN);
    }
});

it('mints the same ids again after a DML-only reset and re-seed, with no DDL', function (): void {
    // Not migrate:fresh: on MariaDB its DDL commits implicitly, which would leak these seeded
    // rows into every later test under RefreshDatabase. This stays inside the test's own
    // transaction and proves the same thing: a fresh seed reproduces the documented ids.
    $fuelId = Taxonomy::query()->where('name', 'fuel')->value('id');
    $before = [
        'make' => Make::query()->where('slug', 'dacia')->value('public_id'),
        'model' => VehicleModel::query()->where('slug', 'dacia-duster')->value('public_id'),
        'manufacturer' => Manufacturer::query()->where('slug', 'bmw')->value('public_id'),
        'taxonomy' => Taxonomy::query()->where('name', 'fuel')->value('public_id'),
        'term' => TaxonomyTerm::query()->where('taxonomy_id', $fuelId)->where('code', 'diesel')->value('public_id'),
    ];
    foreach ($before as $key => $id) {
        expect($id)->not->toBeNull("before[{$key}] must exist before the reset");
    }

    RecordSource::query()->delete();
    Make::query()->delete(); // cascades to vd_models then vd_variants
    Manufacturer::query()->delete();
    Taxonomy::query()->delete(); // cascades to vd_taxonomy_terms, vd_taxonomy_labels and both alias tables

    $this->seed(ExampleDataSeeder::class);

    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    expect(Make::query()->where('slug', 'dacia')->value('public_id'))->toBe($before['make'])
        ->and(VehicleModel::query()->where('slug', 'dacia-duster')->value('public_id'))->toBe($before['model'])
        ->and(Manufacturer::query()->where('slug', 'bmw')->value('public_id'))->toBe($before['manufacturer'])
        ->and($fuel->public_id)->toBe($before['taxonomy'])
        ->and(TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->value('public_id'))->toBe($before['term']);
});
