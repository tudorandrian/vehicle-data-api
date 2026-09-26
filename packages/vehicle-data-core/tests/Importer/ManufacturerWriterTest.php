<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\Writers\ManufacturerWriter;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\Source;

it('does not let a make-link mutation from a rolled-back run leak into the next one', function (): void {
    // ManufacturerWriter is bound as part of the ImportPipeline singleton (CoreServiceProvider),
    // so one instance can live across more than one run in the same process (a queue worker, or
    // this very test). It used to memoize Make::all() in an instance property cleared only by
    // finish() — which never runs for an aborted/failed run (see RunAware). A mutation made (and
    // saved) mid-run, then rolled back with the rest of that run's transaction, stayed visible on
    // the cached in-memory Make object: the very next run would see it as "already linked" and
    // skip re-saving it, leaving the database permanently out of sync with what that next
    // (successful) run actually intended to write.
    $make = Make::factory()->create(['slug' => 'dacia', 'manufacturer_id' => null]);
    $source = Source::query()->create([
        'key' => 'wr-test', 'name' => 'Writer Test', 'licence_id' => 'cc0', 'licence_name' => 'CC0',
        'licence_url' => 'https://example.org/licence', 'attribution' => 'Writer Test', 'url' => 'https://example.org',
    ]);
    // Created outside either transaction, with a stable id, so both write() calls below resolve
    // the SAME manufacturer row — exactly like re-importing the same Wikidata QID across two
    // separate `vehicle:import` invocations. If the manufacturer got a different id every time
    // (e.g. a fresh INSERT per call), a stale cache would happen to "self-correct" by mismatching
    // on the id alone, masking the very bug this test exists to catch.
    $manufacturer = Manufacturer::factory()->create(['wikidata_qid' => 'Q1', 'slug' => 'dacia-mfg', 'name' => 'Dacia']);
    $row = new DomainRow('manufacturer', [
        'wikidata_qid' => 'Q1', 'name' => 'Dacia', 'slug' => 'dacia-mfg', 'make_slugs' => ['dacia'],
    ], 'Q1');
    $writer = new ManufacturerWriter;

    // A run that writes the link, then aborts: the transaction rolls back, so the database
    // reverts to "unlinked" (mirrors ImportPipeline::run() rolling back and rethrowing on
    // failure — the outer DB::transaction() there wraps every batch the same way).
    try {
        DB::transaction(function () use ($writer, $row, $source): void {
            $writer->write($row, $source, now());
            throw new RuntimeException('simulated aborted run');
        });
    } catch (RuntimeException) {
        // expected
    }
    expect($make->fresh()->manufacturer_id)->toBeNull();

    // The next run (same writer instance, same process, same manufacturer row) must still
    // correctly link the make — not silently skip it because a stale cached Make object already
    // "looks" linked to that same manufacturer id.
    DB::transaction(fn () => $writer->write($row, $source, now()));
    expect($make->fresh()->manufacturer_id)->toBe($manufacturer->id);
});
