<?php

declare(strict_types=1);

namespace VehicleData\Core\Usage;

/**
 * Nearest-rank percentile for small in-memory sets (`PerformanceTest`, and
 * the reference implementation `AggregateDailyUsage::p95For()` mirrors in
 * SQL for whole days - keep the index formula identical in both places).
 */
final class Percentile
{
    /**
     * Nearest-rank p95: index `ceil(0.95 * n) - 1` on the values sorted
     * ascending (n = count($values)). Returns 0 for an empty list.
     *
     * @param  list<int|float>  $values
     */
    public static function p95(array $values): int|float
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $n = count($values);
        $index = max(0, min($n - 1, (int) ceil(0.95 * $n) - 1));

        return $values[$index];
    }
}
