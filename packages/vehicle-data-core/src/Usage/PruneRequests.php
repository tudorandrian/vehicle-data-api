<?php

declare(strict_types=1);

namespace VehicleData\Core\Usage;

use Illuminate\Support\Facades\DB;
use VehicleData\Core\Models\ApiRequest;

/**
 * Deletes raw request rows older than core.request_retention_days (the daily aggregate in
 * vd_api_usage_daily is what survives). Chunked so a first run on a large table never holds
 * one long transaction on shared hosting. Also deletes expired vd_api_counters rows (rate
 * limit, quota and failed-authentication counters) — their expires_at margin is generous, so
 * a stale row is a bounded amount of dead weight, not a correctness issue, until this runs.
 */
final class PruneRequests
{
    public static function run(int $chunk = 5000): int
    {
        $chunk = max(1, $chunk);
        $cutoff = now()->subDays((int) config('core.request_retention_days', 90));
        $total = 0;
        do {
            $deleted = ApiRequest::query()->where('created_at', '<', $cutoff)->limit($chunk)->delete();
            $total += $deleted;
        } while ($deleted === $chunk);

        DB::table('vd_api_counters')->where('expires_at', '<', now())->delete();

        return $total;
    }
}
