<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\SourceSeeder;
use VehicleData\Core\Database\Seeders\TaxonomySeeder;
use VehicleData\Core\Importers\CatalogueIdentity;
use VehicleData\Core\Importers\RowRejected;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\PublicId;

beforeEach(function (): void {
    $this->seed(TaxonomySeeder::class);
    $this->seed(SourceSeeder::class);
    $this->source = Source::query()->where('key', 'eea')->firstOrFail();
    $this->retrievedAt = now();
});

it('converges two raw spellings on one make and records both as provenance', function (): void {
    $a = CatalogueIdentity::make($this->source, 'VW', 'Volkswagen', 'car', $this->retrievedAt);
    $b = CatalogueIdentity::make($this->source, 'VOLKSWAGEN', 'Volkswagen', 'car', $this->retrievedAt);
    expect($b->id)->toBe($a->id)->and(Make::count())->toBe(1)
        ->and(RecordSource::query()->where('record_type', $a->getMorphClass())->where('record_id', $a->id)->pluck('source_ref')->sort()->values()->all())->toBe(['VOLKSWAGEN', 'VW']);
});

it('renames a make, keeps its id, aliases the old slug and drops an alias equal to the new slug', function (): void {
    $before = CatalogueIdentity::make($this->source, 'ROLLS ROYCE', 'Rolls Royce Motor', 'car', $this->retrievedAt);
    expect($before->slug)->toBe('rolls-royce-motor');
    $after = CatalogueIdentity::make($this->source, 'ROLLS ROYCE', 'Rolls-Royce', 'car', $this->retrievedAt);
    expect($after->id)->toBe($before->id)->and($after->public_id)->toBe($before->public_id)
        ->and($after->fresh()->slug)->toBe('rolls-royce')
        ->and(SlugAlias::query()->where('record_id', $before->id)->pluck('slug')->all())->toBe(['rolls-royce-motor']);
    // Renamed back: the alias that now equals the live slug is removed.
    CatalogueIdentity::make($this->source, 'ROLLS ROYCE', 'Rolls Royce Motor', 'car', $this->retrievedAt);
    expect(SlugAlias::query()->where('record_id', $before->id)->pluck('slug')->all())->toBe(['rolls-royce']);
});

it('rejects a rename whose new slug belongs to another live make', function (): void {
    CatalogueIdentity::make($this->source, 'DACIA', 'Dacia', 'car', $this->retrievedAt);
    CatalogueIdentity::make($this->source, 'DATSUN', 'Datsun', 'car', $this->retrievedAt);
    expect(fn () => CatalogueIdentity::make($this->source, 'DATSUN', 'Dacia', 'car', $this->retrievedAt))->toThrow(RowRejected::class, 'slug_collision');
});

it('scopes model identity to the resolved make', function (): void {
    $vw = CatalogueIdentity::make($this->source, 'VW', 'Volkswagen', 'car', $this->retrievedAt);
    $golf = CatalogueIdentity::model($this->source, $vw, 'GOLF', 'Golf', $this->retrievedAt);
    $again = CatalogueIdentity::model($this->source, $vw, 'GOLF', 'Golf', $this->retrievedAt);
    expect($again->id)->toBe($golf->id)->and($golf->slug)->toBe('volkswagen-golf');
});

it('accepts a make raw value at exactly 120 chars and rejects one at 121', function (): void {
    $raw120 = str_repeat('a', 120);
    $raw121 = str_repeat('a', 121);
    expect(CatalogueIdentity::make($this->source, $raw120, 'Foo120', 'car', $this->retrievedAt))->toBeInstanceOf(Make::class);
    expect(fn () => CatalogueIdentity::make($this->source, $raw121, 'Foo121', 'car', $this->retrievedAt))->toThrow(RowRejected::class, 'ref_too_long');
});

it('accepts a model ref (make public_id|raw) at exactly 120 chars and rejects one at 121', function (): void {
    $make = CatalogueIdentity::make($this->source, 'VW', 'Volkswagen', 'car', $this->retrievedAt);
    // The ref is "{public_id}|{raw}": public_id is 26 chars, plus the '|' separator, leaves 93 chars
    // of raw before hitting the 120-char column limit.
    $prefixLength = strlen($make->public_id) + 1;
    $raw120 = str_repeat('b', 120 - $prefixLength);
    $raw121 = str_repeat('b', 121 - $prefixLength);
    expect(CatalogueIdentity::model($this->source, $make, $raw120, 'Bar120', $this->retrievedAt))->toBeInstanceOf(VehicleModel::class);
    expect(fn () => CatalogueIdentity::model($this->source, $make, $raw121, 'Bar121', $this->retrievedAt))->toThrow(RowRejected::class, 'ref_too_long');
});

it('rejects a make whose slug is already used by a live make of another kind', function (): void {
    Make::factory()->create(['slug' => 'bmw', 'name' => 'BMW', 'kind' => 'moped']);
    expect(fn () => CatalogueIdentity::make($this->source, 'BMW', 'BMW', 'car', $this->retrievedAt))->toThrow(RowRejected::class, 'slug_collision');
});

it('rejects a rename that would retire a slug already held as an alias by a different record', function (): void {
    $vw = CatalogueIdentity::make($this->source, 'VW', 'Volkswagen', 'car', $this->retrievedAt);
    $golf = CatalogueIdentity::model($this->source, $vw, 'GOLF', 'Golf Old', $this->retrievedAt);
    expect($golf->slug)->toBe('volkswagen-golf-old');
    // Simulate the slug the rename is about to retire already being claimed as another record's alias.
    SlugAlias::query()->create(['record_type' => $golf->getMorphClass(), 'record_id' => $golf->id + 1000, 'slug' => 'volkswagen-golf-old']);

    expect(fn () => CatalogueIdentity::model($this->source, $vw, 'GOLF', 'Golf New', $this->retrievedAt))->toThrow(RowRejected::class, 'slug_collision');

    expect(SlugAlias::query()->where('slug', 'volkswagen-golf-old')->value('record_id'))->toBe($golf->id + 1000)
        ->and($golf->fresh()->slug)->toBe('volkswagen-golf-old')->and($golf->fresh()->name)->toBe('Golf Old');
});

it('mints a make id from the source key and raw value, and keeps it across a rename', function (): void {
    $make = CatalogueIdentity::make($this->source, 'DACIA', 'Dacia', 'car', $this->retrievedAt);
    expect($make->public_id)->toBe(PublicId::for('make|eea|DACIA'));
    $renamed = CatalogueIdentity::make($this->source, 'DACIA', 'Dacia Automobile', 'car', $this->retrievedAt);
    expect($renamed->public_id)->toBe($make->public_id)
        ->and($renamed->fresh()->name)->toBe('Dacia Automobile')
        ->and($renamed->fresh()->slug)->toBe('dacia-automobile');
});

it('keeps an existing public_id when a provenance row already resolves the make, even if it is not the deterministic value', function (): void {
    // Simulates a row minted before this feature (or otherwise carrying a public_id that does
    // not match PublicId::for(...)): the byProvenance() branch of make() must never touch
    // public_id on a row it finds — only the create() branch mints one, and only once.
    $existing = Make::factory()->create(['slug' => 'seat', 'name' => 'Seat', 'kind' => 'car']);
    RecordSource::query()->create([
        'record_type' => $existing->getMorphClass(), 'record_id' => $existing->id,
        'source_id' => $this->source->id, 'source_ref' => 'SEAT',
        'retrieved_at' => $this->retrievedAt, 'checksum' => hash('sha256', 'SEAT'),
    ]);
    $randomId = $existing->public_id;
    expect($randomId)->not->toBe(PublicId::for('make|eea|SEAT'));

    $resolved = CatalogueIdentity::make($this->source, 'SEAT', 'Seat', 'car', $this->retrievedAt);

    expect($resolved->id)->toBe($existing->id)->and($resolved->public_id)->toBe($randomId);
});

it('scopes the alias namespace to record type: a make and a manufacturer can share and each independently retire the same slug', function (): void {
    // On the live stack, eight slugs exist as both a manufacturer and a make (bmw, dacia, fiat,
    // kia, opel, renault, toyota, volkswagen). Before this fix, CatalogueIdentity::rename() and
    // assertSlugFree() checked vd_slug_aliases.slug with no record_type filter: a manufacturer
    // rename that retired "renault" as a manufacturer-typed alias would then make every later
    // EEA import renaming make "renault" hit that global check and reject with slug_collision
    // forever — and symmetrically block the manufacturer from ever reclaiming its own old slug.
    $manufacturer = Manufacturer::factory()->create(['slug' => 'renault', 'name' => 'Renault', 'wikidata_qid' => 'Q1']);
    $make = Make::factory()->create(['slug' => 'renault', 'name' => 'Renault', 'kind' => 'car']);

    // The manufacturer renames away from "renault": retired as a manufacturer-typed alias.
    CatalogueIdentity::rename(Manufacturer::class, $manufacturer, 'Renault Group', 'renault-group');
    expect(SlugAlias::query()->where('record_type', $manufacturer->getMorphClass())->where('slug', 'renault')->exists())->toBeTrue();

    // The make, sharing the very same slug, must still be able to rename away from "renault" —
    // the manufacturer's alias of the same slug is a different record type, not a collision.
    CatalogueIdentity::rename(Make::class, $make, 'Renault Auto', 'renault-auto');
    expect($make->fresh()->slug)->toBe('renault-auto')
        ->and(SlugAlias::query()->where('record_type', $make->getMorphClass())->where('slug', 'renault')->exists())->toBeTrue();

    // The manufacturer can reclaim its own retired "renault" slug — nothing of its OWN type holds it.
    CatalogueIdentity::rename(Manufacturer::class, $manufacturer->fresh(), 'Renault', 'renault');
    expect($manufacturer->fresh()->slug)->toBe('renault');

    // Same-type reservation is still enforced: another make cannot steal "renault", which the
    // first make just retired as its own (make-typed) alias.
    $other = Make::factory()->create(['slug' => 'unrelated-make', 'name' => 'Unrelated', 'kind' => 'car']);
    expect(fn () => CatalogueIdentity::rename(Make::class, $other, 'Renault', 'renault'))->toThrow(RowRejected::class, 'slug_collision');
});
