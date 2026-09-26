<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyLabel;
use VehicleData\Core\Models\TaxonomyTerm;
use VehicleData\Core\Support\PublicId;
use VehicleData\Core\Support\PublicIdFormat;
use VehicleData\Core\Taxonomies\TaxonomyDefinitions;

beforeEach(fn () => $this->seed(TaxonomySeeder::class));

it('seeds every taxonomy and term from the definitions', function (): void {
    $defs = TaxonomyDefinitions::all();
    expect(Taxonomy::count())->toBe(count($defs))
        ->and(TaxonomyTerm::count())->toBe(array_sum(array_map(fn ($d) => count($d['terms']), $defs)));
});

it('gives every term a ro and an en label that is not the raw key', function (): void {
    expect(TaxonomyLabel::count())->toBe(TaxonomyTerm::count() * 2);
    TaxonomyLabel::query()->each(function (TaxonomyLabel $l): void {
        expect($l->label)->not->toContain('core::')->not->toBe('');
    });
});

it('uses snake_case ASCII codes only', function (): void {
    TaxonomyTerm::query()->each(fn (TaxonomyTerm $t) => expect($t->code)->toMatch('/^[a-z0-9_]+$/'));
});

it('is idempotent', function (): void {
    $before = [Taxonomy::count(), TaxonomyTerm::count(), TaxonomyLabel::count()];
    $this->seed(TaxonomySeeder::class);
    expect([Taxonomy::count(), TaxonomyTerm::count(), TaxonomyLabel::count()])->toBe($before);
});

it('keeps an existing public_id when it runs again, even if it is not the deterministic value', function (): void {
    // Simulates a taxonomy/term row minted before this feature: firstOrCreate() must never
    // touch public_id on a row it finds — only the create() branch mints one, and only once.
    $fuel = Taxonomy::query()->where('name', 'fuel')->firstOrFail();
    $diesel = TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->firstOrFail();
    $fuel->forceFill(['public_id' => PublicIdFormat::generate()])->save();
    $diesel->forceFill(['public_id' => PublicIdFormat::generate()])->save();
    $randomTaxonomyId = $fuel->public_id;
    $randomTermId = $diesel->public_id;
    expect($randomTaxonomyId)->not->toBe(PublicId::for('taxonomy|fuel'))
        ->and($randomTermId)->not->toBe(PublicId::for('term|fuel|diesel'));

    $this->seed(TaxonomySeeder::class);

    expect(Taxonomy::query()->where('name', 'fuel')->value('public_id'))->toBe($randomTaxonomyId)
        ->and(TaxonomyTerm::query()->where('taxonomy_id', $fuel->id)->where('code', 'diesel')->value('public_id'))->toBe($randomTermId);
});

it('uses comma-below diacritics (ș ț) in Romanian labels, never cedilla (ş ţ)', function (): void {
    $term = TaxonomyTerm::query()->where('code', 'motocicleta')->firstOrFail();
    expect($term->labels()->where('locale', 'ro')->value('label'))->toBe('Motocicletă')
        ->and(TaxonomyTerm::query()->where('code', 'autorulota')->firstOrFail()->labels()->where('locale', 'ro')->value('label'))->toBe('Autorulotă');

    $roLabels = TaxonomyLabel::query()->where('locale', 'ro')->pluck('label');

    expect($roLabels->filter(fn (string $l) => str_contains($l, "\u{0219}") || str_contains($l, "\u{021B}")))->not->toBeEmpty();

    $roLabels->each(function (string $label): void {
        expect($label)
            ->not->toContain("\u{015F}")
            ->not->toContain("\u{0163}")
            ->not->toContain("\u{015E}")
            ->not->toContain("\u{0162}");
    });
});
