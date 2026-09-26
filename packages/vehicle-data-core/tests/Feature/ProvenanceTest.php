<?php

declare(strict_types=1);

use VehicleData\Core\Http\Query\Provenance;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Source;

it('reports the newest retrieved_at per source, independent of row/index order', function (): void {
    // A record can carry more than one vd_record_sources row for the same source (e.g. matched
    // once by provenance and once by slug fallback, or two distinct source_ref values that both
    // resolved to it). Provenance::for() used to order only by source_id and keep whichever row
    // came first in that order - effectively index order, not the newest one.
    $make = Make::factory()->create();
    $source = Source::query()->create([
        'key' => 'test-source', 'name' => 'Test Source', 'licence_id' => 'cc0', 'licence_name' => 'CC0',
        'licence_url' => 'https://example.org/licence', 'attribution' => 'Test Source', 'url' => 'https://example.org',
    ]);

    // Deliberately inserted (so given the lower id) BEFORE the newer one: an `orderBy(source_id)`
    // with no tiebreaker returns rows in id order on this engine, so a naive "first row for this
    // source_id" read would report this older timestamp instead of the newest one.
    RecordSource::query()->create([
        'record_type' => $make->getMorphClass(), 'record_id' => $make->id, 'source_id' => $source->id,
        'source_ref' => 'ref-older', 'retrieved_at' => '2026-09-17T13:08:08+00:00', 'checksum' => hash('sha256', 'older'),
    ]);
    RecordSource::query()->create([
        'record_type' => $make->getMorphClass(), 'record_id' => $make->id, 'source_id' => $source->id,
        'source_ref' => 'ref-newer', 'retrieved_at' => '2026-09-17T13:08:41+00:00', 'checksum' => hash('sha256', 'newer'),
    ]);

    $rows = Provenance::for($make);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['key'])->toBe('test-source')
        ->and($rows[0]['retrieved_at'])->toBe('2026-09-17T13:08:41+00:00');
});
