<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use VehicleData\Core\Auth\Counters;
use VehicleData\Core\Usage\PruneRequests;

it('counts hits per scope and window with one statement and returns the running count', function (): void {
    [$w, $end] = Counters::minuteWindow();
    DB::enableQueryLog();
    expect(Counters::hit('client:1', $w, $end))->toBe(1)
        ->and(Counters::hit('client:1', $w, $end))->toBe(2)
        ->and(Counters::hit('client:2', $w, $end))->toBe(1);
    expect(DB::getQueryLog())->toHaveCount(3);
});

it('names windows in utc and gives their end', function (): void {
    $this->travelTo('2026-09-17 07:41:30 UTC');
    [$m, $mEnd] = Counters::minuteWindow();
    [$d, $dEnd] = Counters::dayWindow();
    expect($m)->toBe('m:2026-09-17T07:41')->and($mEnd->toIso8601String())->toBe('2026-09-17T07:41:59+00:00')
        ->and($d)->toBe('d:2026-09-17')->and($dEnd->toIso8601String())->toBe('2026-09-17T23:59:59+00:00');
});

it('keeps expires_at fixed across repeated hits in the same window', function (): void {
    // expires_at is a plain DATETIME column (not TIMESTAMP): MariaDB gives the
    // first TIMESTAMP column in a table an implicit ON UPDATE CURRENT_TIMESTAMP
    // unless explicit_defaults_for_timestamp is set, which would silently reset
    // expires_at — and so the quota window — to "now" on every upsert.
    [$w, $end] = Counters::minuteWindow();
    $plannedExpiry = $end->addDay();
    Counters::hit('client:1', $w, $plannedExpiry);
    Counters::hit('client:1', $w, $plannedExpiry);
    $stored = DB::table('vd_api_counters')->where('scope', 'client:1')->where('window', $w)->value('expires_at');
    expect(CarbonImmutable::parse($stored, 'UTC')->getTimestamp())->toBe($plannedExpiry->getTimestamp());
});

it('keeps a not-yet-expired counter row through prune', function (): void {
    [$w, $end] = Counters::minuteWindow();
    Counters::hit('client:9', $w, $end->addDay());
    Counters::hit('client:9', $w, $end->addDay());
    PruneRequests::run();
    expect(DB::table('vd_api_counters')->where('scope', 'client:9')->exists())->toBeTrue();
});

it('backticks the reserved `window` identifier in the counter upsert', function (): void {
    // window is reserved in MySQL 8 (and window-function-aware MariaDB); backtick it so the
    // raw upsert keeps working on any MySQL-family server, not just the one this suite runs on.
    [$w, $end] = Counters::minuteWindow();
    $captured = null;
    DB::listen(function ($query) use (&$captured): void {
        if (str_contains($query->sql, 'INSERT INTO vd_api_counters')) {
            $captured = $query->sql;
        }
    });
    Counters::hit('client:bt', $w, $end);
    expect($captured)->not->toBeNull()->and($captured)->toContain('`window`');
});

it('falls back to a fresh SELECT when the connection reports a stale last-insert-id of 0', function (): void {
    // A transparent reconnect between the upsert and the lastInsertId() read (e.g. "MySQL
    // server has gone away", or a connection pooler swapping the backend session) starts a
    // fresh session whose LAST_INSERT_ID() is 0 — indistinguishable, without a fallback, from
    // "under the limit" for both the rate and quota limiters. Reproduce that stale-zero read
    // deterministically: reset LAST_INSERT_ID() on the very connection Counters::hit() uses,
    // right after its own upsert commits and before it reads the value back.
    [$w, $end] = Counters::minuteWindow();
    Counters::hit('client:stale', $w, $end);
    Counters::hit('client:stale', $w, $end);

    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'ON DUPLICATE KEY UPDATE')) {
            DB::statement('SELECT LAST_INSERT_ID(0)');
        }
    });

    // 3 is also what the buggy, fallback-less code would happen to return here (nothing else in
    // this test moves the count away from 3), so asserting only the return value would pass
    // whether or not the fallback SELECT actually ran. Prove it ran: enable the query log for
    // just this call and require a `select` against vd_api_counters in it.
    DB::enableQueryLog();
    expect(Counters::hit('client:stale', $w, $end))->toBe(3);
    $fallbackSelect = collect(DB::getQueryLog())->first(
        fn (array $q): bool => str_starts_with(trim(strtolower((string) $q['query'])), 'select')
            && str_contains((string) $q['query'], 'vd_api_counters')
    );
    expect($fallbackSelect)->not->toBeNull();
});
