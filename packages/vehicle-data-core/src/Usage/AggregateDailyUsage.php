<?php

declare(strict_types=1);

namespace VehicleData\Core\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use VehicleData\Core\Models\ApiClient;
use VehicleData\Core\Models\ApiUsageDaily;

/**
 * Rolls up `vd_api_requests` for one calendar day into one
 * `vd_api_usage_daily` row per client (`requests`, `errors` - status >= 400
 * - and `p95_ms`, computed in the database by {@see self::p95For()}, whose
 * index formula mirrors {@see Percentile::p95()}). Idempotent: re-running
 * the same date upserts the same row rather than duplicating it.
 */
final class AggregateDailyUsage
{
    public static function forDate(CarbonImmutable $date): int
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();

        // Plain DB::table() (stdClass rows), not the ApiRequest Eloquent model:
        // `requests`/`errors` are aggregate aliases, not real columns on that
        // model.
        $rows = DB::table('vd_api_requests')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('client_id, COUNT(*) AS requests, SUM(status >= 400) AS errors, MAX(created_at) AS last_created_at')
            ->groupBy('client_id')
            ->get();

        foreach ($rows as $r) {
            $clientId = $r->client_id === null ? null : (int) $r->client_id;

            $p95 = self::p95For($clientId, $start, $end);

            ApiUsageDaily::query()->updateOrCreate(
                ['client_id' => $clientId, 'date' => $date->toDateString()],
                [
                    'requests' => (int) $r->requests,
                    'errors' => (int) $r->errors,
                    'p95_ms' => $p95,
                ],
            );

            if ($clientId !== null) {
                // Never move last_used_at backwards: aggregating an older date (e.g. a
                // backfill) after a more recent one must not overwrite the newer value.
                ApiClient::query()->whereKey($clientId)
                    ->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', $r->last_created_at))
                    ->update(['last_used_at' => $r->last_created_at]);
            }
        }

        return $rows->count();
    }

    /**
     * Nearest-rank p95 computed by the database: the row at offset
     * ceil(0.95·n) − 1 of the durations sorted ascending - the same index
     * Percentile::p95() uses - fetched with LIMIT 1, so memory stays flat
     * however many requests a client made that day (R11).
     */
    private static function p95For(?int $clientId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $base = DB::table('vd_api_requests')->whereBetween('created_at', [$start, $end])
            ->when($clientId === null, fn ($q) => $q->whereNull('client_id'), fn ($q) => $q->where('client_id', $clientId));
        $n = (clone $base)->count();
        if ($n === 0) {
            return 0;
        }
        $offset = max(0, min($n - 1, (int) ceil(0.95 * $n) - 1));

        return (int) (clone $base)->orderBy('duration_ms')->offset($offset)->limit(1)->value('duration_ms');
    }
}
