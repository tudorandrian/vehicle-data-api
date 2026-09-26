<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\ImportPipeline;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\LicenceNotAdmitted;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\Slug;

function fakeSource(array $rows): DataSource
{
    return new class($rows) implements DataSource
    {
        public function __construct(private array $rows) {}

        public function key(): string
        {
            return 'fake';
        }

        public function name(): string
        {
            return 'Fake';
        }

        public function url(): string
        {
            return 'https://example.org/fake';
        }

        public function licence(): Licence
        {
            return Licence::cc0();
        }

        public function fetch(ImportOptions $o): iterable
        {
            // Deliberately ignores $o->limit - ImportPipeline::run itself must stop
            // reading after $options->limit rows, so this exercises that production code.
            foreach ($this->rows as $i => $r) {
                yield new RawRow($r, 'row-'.$i);
            }
        }

        public function map(RawRow $r): ?DomainRow
        {
            if (($r->data['make'] ?? '') === '') {
                return $r->reject('empty');
            }
            // 'make'/'model' are the NORMALISED name; 'make_raw'/'model_raw' default to the same value but
            // can be overridden by a row, so a test can keep the raw value constant (as a real source
            // would across rows) while varying the normalised name to force a rename.
            $mk = $r->data['make'];
            $md = $r->data['model'];
            $mkRaw = $r->data['make_raw'] ?? $mk;
            $mdRaw = $r->data['model_raw'] ?? $md;

            return new DomainRow('variant', ['make_name' => $mk, 'make_slug' => Slug::make($mk), 'make_raw' => $mkRaw, 'model_name' => $md, 'model_slug' => Slug::make($mk.' '.$md), 'model_raw' => $mdRaw,
                'natural_key' => "fake|$mk|$md|{$r->data['fuel']}", 'fuel_code' => $r->data['fuel'], 'eu_category_code' => 'm1', 'power_kw' => $r->data['kw'] ?? null, 'year_from' => 2024], $r->ref);
        }

        public function naturalKey(DomainRow $d): string
        {
            return $d->attributes['natural_key'];
        }
    };
}

it('writes make, model and variant with provenance, and is idempotent', function (): void {
    // Provenance is one vd_record_sources row per (record, source, source_ref). Each writer call also
    // asks CatalogueIdentity to resolve the make/model by their RAW value, which records its own
    // provenance row keyed by that raw value (make 'DACIA'; model make-public-id|'DUSTER') - the two raw
    // rows share the same raw make/model, so that adds exactly one row each. On top of that,
    // ImportPipeline still records one row per touched record per distinct row-level source_ref
    // (row-0, row-1): make +2, model +2, variant +2 (variants differ per row, so no CatalogueIdentity
    // involvement there). Total: make 1+2=3, model 1+2=3, variant 2, overall 8.
    $rows = [['make' => 'DACIA', 'model' => 'DUSTER', 'fuel' => 'petrol', 'kw' => 110], ['make' => 'DACIA', 'model' => 'DUSTER', 'fuel' => 'diesel', 'kw' => 84]];
    $run = app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions);
    expect($run->status)->toBe('succeeded')->and($run->rows_read)->toBe(2)->and($run->rows_written)->toBe(2)->and(Variant::count())->toBe(2)
        ->and(RecordSource::where('record_type', 'variant')->count())->toBe(2)
        ->and(RecordSource::where('record_type', 'make')->count())->toBe(3)
        ->and(RecordSource::where('record_type', 'model')->count())->toBe(3)
        ->and(RecordSource::count())->toBe(8);
    app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions);
    expect(Variant::count())->toBe(2)->and(RecordSource::count())->toBe(8)->and(ImportRun::count())->toBe(2);
});

it('does not fire an UPDATE per row against vd_record_sources for a make/model shared across many rows', function (): void {
    // CatalogueIdentity::provenance() used to pass now() as retrieved_at, which differs on every
    // call, so the make's/model's identity-resolution row was dirty on every single row referring
    // to it and Eloquent fired an UPDATE each time - for a real import, a handful of makes/models
    // referenced by millions of rows. ImportPipeline now threads one retrieved_at per run through
    // CatalogueIdentity::make()/model(), so after the first row (an INSERT, the row does not exist
    // yet) every later row resolving the SAME make/model raw value leaves that row's attributes
    // unchanged and Eloquent skips the UPDATE entirely, regardless of how many rows there are.
    $n = 20;
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = ['make' => 'Dacia', 'model' => 'Duster', 'fuel' => 'petrol', 'kw' => 100 + $i];
    }
    DB::enableQueryLog();
    $run = app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions);
    expect($run->status)->toBe('succeeded')->and($run->rows_written)->toBe($n);

    $updatesOnRecordSources = collect(DB::getQueryLog())->filter(
        fn (array $q): bool => str_starts_with(trim(strtolower((string) $q['query'])), 'update')
            && str_contains((string) $q['query'], 'vd_record_sources')
    )->count();
    // Zero, not "fewer than $n": the make/model's own identity-resolution row is only ever
    // INSERTed once (row 0) and never changes again this run, so no UPDATE against
    // vd_record_sources should happen at all - pinning this at a fixed ceiling (rather than one
    // that grows with $n) is what proves the property, not just that it is "better than before".
    expect($updatesOnRecordSources)->toBe(0);
});

it('records rejects with rule and sample, and aborts above the reject share', function (): void {
    $rows = array_merge(array_fill(0, 95, ['make' => 'X', 'model' => 'Y', 'fuel' => 'petrol']), array_fill(0, 10, ['make' => '', 'model' => 'Y', 'fuel' => 'petrol']));
    expect(fn () => app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions(rejectShare: 0.05)))->toThrow(RuntimeException::class, 'Aborted');
    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('aborted')->and($run->reject_report['summary'])->toBe(['empty' => 6])->and($run->reject_report['samples'][0]['rule'])->toBe('empty');
});

it('honours --limit', function (): void {
    $rows = array_fill(0, 30, ['make' => 'A', 'model' => 'B', 'fuel' => 'petrol']);
    $run = app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions(limit: 5));
    expect($run->rows_read)->toBe(5);
});

it('counts writer-level rejects (ref_too_long, slug_collision) in the reject report without aborting the run', function (): void {
    Make::factory()->create(['slug' => 'existing', 'name' => 'Existing', 'kind' => 'car']);
    $rows = [
        // Establishes a make known by provenance under raw 'XYZ', so the third row's rename attempt has
        // something to rename.
        ['make' => 'Xyz', 'make_raw' => 'XYZ', 'model' => 'M0', 'fuel' => 'petrol'],
        // A 121-char raw make value: rejected before any writes (ref_too_long).
        ['make' => 'LongMake', 'make_raw' => str_repeat('a', 121), 'model' => 'M1', 'fuel' => 'diesel'],
        // Same raw 'XYZ' as row 0 (found by provenance), but a normalised name that renames it onto a
        // slug already used by another live make (slug_collision).
        ['make' => 'Existing', 'make_raw' => 'XYZ', 'model' => 'M2', 'fuel' => 'lpg'],
    ];
    $run = app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions);
    expect($run->status)->toBe('succeeded')->and($run->rows_read)->toBe(3)->and($run->rows_written)->toBe(1)->and($run->rows_rejected)->toBe(2)
        ->and($run->reject_report['summary'])->toMatchArray(['ref_too_long' => 1, 'slug_collision' => 1])
        ->and(Variant::count())->toBe(1);
});

it('rolls back a rejected row\'s partial writes: a make rename is undone when the same row\'s model creation collides', function (): void {
    app(ImportPipeline::class)->run(fakeSource([['make' => 'Xyz', 'make_raw' => 'XYZ', 'model' => 'M0', 'fuel' => 'petrol']]), new ImportOptions);
    $xyz = Make::query()->where('slug', 'xyz')->firstOrFail();

    // Pre-create a different make/model whose slug the rename below would collide with: after Xyz
    // renames to 'Existing' (slug 'existing'), resolving model raw 'WIDGET' would compute slug
    // 'existing-widget' - already taken here by an unrelated make's model.
    $beta = Make::factory()->create(['slug' => 'beta', 'name' => 'Beta', 'kind' => 'car']);
    VehicleModel::factory()->create(['make_id' => $beta->id, 'slug' => 'existing-widget', 'name' => 'Widget']);

    $run = app(ImportPipeline::class)->run(
        fakeSource([['make' => 'Existing', 'make_raw' => 'XYZ', 'model' => 'Widget', 'model_raw' => 'WIDGET', 'fuel' => 'diesel']]),
        new ImportOptions,
    );

    expect($run->status)->toBe('succeeded')->and($run->rows_written)->toBe(0)->and($run->rows_rejected)->toBe(1)
        ->and($run->reject_report['summary'])->toMatchArray(['slug_collision' => 1]);
    expect($xyz->fresh()->name)->toBe('Xyz')->and($xyz->fresh()->slug)->toBe('xyz')
        ->and(SlugAlias::query()->where('record_id', $xyz->id)->exists())->toBeFalse();
});

it('refuses a source whose licence is not admitted before creating a run or writing anything', function (): void {
    $source = new class implements DataSource
    {
        public function key(): string
        {
            return 'sa';
        }

        public function name(): string
        {
            return 'Share-alike';
        }

        public function url(): string
        {
            return 'https://example.org/sa';
        }

        public function licence(): Licence
        {
            return new Licence('CC-BY-SA-4.0', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', 'x');
        }

        public function fetch(ImportOptions $o): iterable
        {
            yield new RawRow(['make' => 'Dacia', 'model' => 'Logan', 'fuel' => 'petrol'], 'r1');
        }

        public function map(RawRow $r): ?DomainRow
        {
            return null;
        }

        public function naturalKey(DomainRow $row): string
        {
            return '';
        }
    };
    expect(fn () => app(ImportPipeline::class)->run($source, new ImportOptions))
        ->toThrow(LicenceNotAdmitted::class, 'CC-BY-SA-4.0');
    expect(ImportRun::query()->count())->toBe(0)
        ->and(Source::query()->where('key', 'sa')->exists())->toBeFalse();
});

it('re-checks the reject share after the final flush so writer-level rejects there can still abort the run', function (): void {
    // All 100 rows land in a single batch (well under ImportPipeline::BATCH), so every writer-level
    // reject here is only recorded during the trailing flush() call after the fetch loop ends - the
    // per-iteration abort check (which runs before that flush) never sees them. This exercises the
    // post-final-flush recheck specifically.
    $rows = [];
    for ($i = 0; $i < 94; $i++) {
        $rows[] = ['make' => "Make{$i}", 'model' => 'M', 'fuel' => 'petrol'];
    }
    for ($i = 0; $i < 6; $i++) {
        Make::factory()->create(['slug' => "clash{$i}", 'name' => "Clash{$i}", 'kind' => 'moped']);
        $rows[] = ['make' => "Clash{$i}", 'model' => 'M', 'fuel' => 'diesel'];
    }
    expect(fn () => app(ImportPipeline::class)->run(fakeSource($rows), new ImportOptions(rejectShare: 0.05)))->toThrow(RuntimeException::class, 'Aborted');
    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('aborted')->and($run->reject_report['summary']['slug_collision'] ?? null)->toBe(6);
});
