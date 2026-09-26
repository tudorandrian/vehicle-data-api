<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\SlugAlias;

/** Writes a fixture built from the base one, with $mutate applied to every Q27460 (Dacia) binding. */
function wikidataFixtureWithDaciaMutated(callable $mutate): string
{
    $fx = json_decode((string) file_get_contents(base_path(WD_FIXTURE)), true);
    foreach ($fx['sparql']['results']['bindings'] as &$b) {
        if (($b['item']['value'] ?? '') === 'http://www.wikidata.org/entity/Q27460') {
            $mutate($b);
        }
    }
    unset($b);
    $path = tempnam(sys_get_temp_dir(), 'wd').'.json';
    file_put_contents($path, json_encode($fx));

    return $path;
}

const WD_FIXTURE = 'packages/vehicle-data-core/database/fixtures/wikidata_manufacturers.json';

it('creates manufacturers, links makes by label, resolves parents and keeps only free logos', function (): void {
    Make::factory()->create(['slug' => 'dacia', 'name' => 'Dacia']);
    Make::factory()->create(['slug' => 'renault', 'name' => 'Renault']);
    Make::factory()->create(['slug' => 'tesla', 'name' => 'Tesla']);
    Make::factory()->create(['slug' => 'skoda', 'name' => 'Škoda']);
    Make::factory()->create(['slug' => 'ford', 'name' => 'Ford']);

    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => base_path(WD_FIXTURE)])->assertExitCode(0);

    $dacia = Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail();
    expect($dacia->country_code)->toBe('RO')
        ->and($dacia->founded_year)->toBe(1966)
        ->and(Make::query()->where('slug', 'dacia')->value('manufacturer_id'))->toBe($dacia->id);

    // Unconditional — the fixture's real Wikidata data links Dacia to Renault (P749).
    expect($dacia->parent_slug)->toBe('renault');

    // Loose make matching: "Tesla, Inc." and "Škoda Auto" labels still link the plain "tesla"/"skoda" makes.
    expect(Make::query()->where('slug', 'tesla')->value('manufacturer_id'))->not->toBeNull()
        ->and(Make::query()->where('slug', 'skoda')->value('manufacturer_id'))->not->toBeNull()
        ->and(Make::query()->where('slug', 'ford')->value('manufacturer_id'))->not->toBeNull();

    // Assert at least one free logo exists before iterating logos.
    expect(Manufacturer::query()->whereNotNull('logo_commons_file')->exists())->toBeTrue();
    Manufacturer::query()->whereNotNull('logo_commons_file')->each(fn (Manufacturer $m) => expect($m->logo_licence)->not->toContain('SA'));
});

it('drops a share-alike logo but keeps the manufacturer', function (): void {
    $fx = json_decode((string) file_get_contents(base_path(WD_FIXTURE)), true);
    foreach ($fx['sparql']['results']['bindings'] as &$b) {
        if (($b['item']['value'] ?? '') === 'http://www.wikidata.org/entity/Q27460') {
            $b['logo'] = ['type' => 'uri', 'value' => 'http://commons.wikimedia.org/wiki/Special:FilePath/Dacia%20SA.svg'];
        }
    }
    unset($b);
    $fx['commons']['Dacia SA.svg'] = 'CC BY-SA 4.0';
    $tmp = tempnam(sys_get_temp_dir(), 'wd').'.json';
    file_put_contents($tmp, json_encode($fx));

    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $tmp])->assertExitCode(0);

    $dacia = Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail();
    expect($dacia->logo_commons_file)->toBeNull()->and($dacia->logo_licence)->toBeNull();
});

it('merges a multi-valued Wikidata item deterministically, independent of binding order', function (): void {
    // Dacia/Q27460 has two P749 "parent" values in the live fixture (Q6686 and Q1477864).
    // Swapping the order of those two bindings — everything else in the file untouched —
    // must not change any field of the resulting manufacturer row.
    $fx = json_decode((string) file_get_contents(base_path(WD_FIXTURE)), true);
    $bindings = $fx['sparql']['results']['bindings'];
    $daciaIndexes = array_keys(array_filter(
        $bindings,
        fn (array $b): bool => ($b['item']['value'] ?? '') === 'http://www.wikidata.org/entity/Q27460',
    ));
    expect($daciaIndexes)->toHaveCount(2);
    [$i, $j] = $daciaIndexes;

    $reversed = $fx;
    $reversed['sparql']['results']['bindings'][$i] = $bindings[$j];
    $reversed['sparql']['results']['bindings'][$j] = $bindings[$i];

    $fields = ['country_code', 'founded_year', 'parent_slug', 'website', 'logo_commons_file', 'logo_licence'];

    $pathA = tempnam(sys_get_temp_dir(), 'wda').'.json';
    file_put_contents($pathA, json_encode($fx));
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $pathA])->assertExitCode(0);
    $a = Arr::only(Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail()->toArray(), $fields);

    $pathB = tempnam(sys_get_temp_dir(), 'wdb').'.json';
    file_put_contents($pathB, json_encode($reversed));
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $pathB])->assertExitCode(0);
    $b = Arr::only(Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail()->toArray(), $fields);

    expect($a)->toBe($b)->and($a['parent_slug'])->toBe('renault');
});

it('never resolves a manufacturer as its own parent', function (): void {
    $fx = json_decode((string) file_get_contents(base_path(WD_FIXTURE)), true);
    foreach ($fx['sparql']['results']['bindings'] as &$b) {
        if (($b['item']['value'] ?? '') === 'http://www.wikidata.org/entity/Q27460') {
            $b['parent'] = ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q27460'];
        }
    }
    unset($b);
    $tmp = tempnam(sys_get_temp_dir(), 'wdself').'.json';
    file_put_contents($tmp, json_encode($fx));

    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $tmp])->assertExitCode(0);

    $dacia = Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail();
    expect($dacia->parent_qid)->toBe('Q27460')->and($dacia->parent_slug)->toBeNull();
});

it('rejects a row whose slug is already taken by a manufacturer with a different QID', function (): void {
    // The fixture's own Wikidata data has this collision: two distinct QIDs both labelled
    // "Renault" (a holding company and the brand entity) normalise to the same slug. The
    // first one written keeps the slug; the second is rejected, and the import still succeeds.
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => base_path(WD_FIXTURE)])->assertExitCode(0);

    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('succeeded')
        ->and($run->reject_report['summary']['duplicate_slug'] ?? 0)->toBeGreaterThan(0)
        ->and(Manufacturer::query()->where('slug', 'renault')->count())->toBe(1);
});

it('renames a manufacturer through the same alias machinery as a make or model: id kept, old slug 301s', function (): void {
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => base_path(WD_FIXTURE)])->assertExitCode(0);
    $before = Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail();
    expect($before->slug)->toBe('dacia');

    $renamed = wikidataFixtureWithDaciaMutated(function (array &$b): void {
        $b['label']['value'] = 'Dacia Motors';
        $b['itemLabel']['value'] = 'Dacia Motors';
    });
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $renamed])->assertExitCode(0);

    $after = Manufacturer::query()->where('wikidata_qid', 'Q27460')->firstOrFail();
    expect($after->id)->toBe($before->id)
        ->and($after->slug)->toBe('dacia-motors')
        ->and(SlugAlias::query()->where('record_type', $after->getMorphClass())->where('record_id', $after->id)->where('slug', 'dacia')->exists())->toBeTrue();

    [, $key] = keyed();
    $location = $this->getJson('/v1/manufacturers/dacia', bearer($key))->assertStatus(301)->headers->get('Location');
    expect((string) $location)->toEndWith('/v1/manufacturers/dacia-motors');
});

it('reserves a manufacturer\'s retired slug: a later, unrelated manufacturer cannot claim it', function (): void {
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => base_path(WD_FIXTURE)])->assertExitCode(0);

    $renamed = wikidataFixtureWithDaciaMutated(function (array &$b): void {
        $b['label']['value'] = 'Dacia Motors';
        $b['itemLabel']['value'] = 'Dacia Motors';
    });
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $renamed])->assertExitCode(0);
    expect(Manufacturer::query()->where('slug', 'dacia')->exists())->toBeFalse();

    // A brand-new QID, unrelated to the Dacia that just moved off "dacia", labelled "Dacia":
    // must be rejected (duplicate_slug), not handed the freed-looking slug. Built from the
    // already-renamed fixture, not the base one — the base fixture's own Q27460 binding is
    // still labelled "Dacia" and would otherwise rename Dacia straight back to "dacia" itself.
    $fx = json_decode((string) file_get_contents($renamed), true);
    $fx['sparql']['results']['bindings'][] = [
        'label' => ['xml:lang' => 'en', 'type' => 'literal', 'value' => 'Dacia'],
        'item' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q99999999'],
        'itemLabel' => ['xml:lang' => 'en', 'type' => 'literal', 'value' => 'Dacia'],
    ];
    $path = tempnam(sys_get_temp_dir(), 'wd').'.json';
    file_put_contents($path, json_encode($fx));
    $this->artisan('vehicle:import', ['source' => 'wikidata', '--file' => $path])->assertExitCode(0);

    $run = ImportRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe('succeeded')
        ->and($run->reject_report['summary']['duplicate_slug'] ?? 0)->toBeGreaterThan(0)
        ->and(Manufacturer::query()->where('wikidata_qid', 'Q99999999')->exists())->toBeFalse()
        ->and(Manufacturer::query()->where('slug', 'dacia')->exists())->toBeFalse();
});
