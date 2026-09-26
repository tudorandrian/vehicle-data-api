<?php

declare(strict_types=1);

namespace VehicleData\Core\Auth;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-window counters for the per-minute rate, the daily quota and the per-IP
 * authentication failures. One upsert per hit, no row lock held across statements
 * (the database cache store needed a SELECT … FOR UPDATE plus an UPDATE per limit).
 * LAST_INSERT_ID(expr) makes MariaDB hand back the new count without a second query.
 */
final class Counters
{
    public static function hit(string $scope, string $window, DateTimeInterface $expiresAt): int
    {
        // `window` is a reserved word in MySQL 8 (and reserved-adjacent in MariaDB): backtick it
        // everywhere it appears as an identifier, both here and in the fallback SELECT below.
        DB::statement(
            'INSERT INTO vd_api_counters (scope, `window`, count, expires_at) VALUES (?, ?, LAST_INSERT_ID(1), ?) '
            .'ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)',
            [$scope, $window, $expiresAt->format('Y-m-d H:i:s')],
        );

        $count = (int) DB::getPdo()->lastInsertId();
        if ($count === 0) {
            // DB::statement() can transparently reconnect (e.g. after "MySQL server has gone
            // away"); a reconnect starts a new session, so lastInsertId() above would then read
            // that fresh session and return 0 - which both limiters (rate and quota) read as
            // "under the limit", silently disabling them. 0 is never a real count (LAST_INSERT_ID(1)
            // seeds it at 1 on first insert), so fall back to reading the row directly.
            $count = (int) DB::table('vd_api_counters')->where('scope', $scope)->where('window', $window)->value('count');
        }

        return $count;
    }

    /** @return array{0: string, 1: CarbonImmutable} */
    public static function minuteWindow(): array
    {
        $now = CarbonImmutable::now('UTC');

        return ['m:'.$now->format('Y-m-d\TH:i'), $now->endOfMinute()];
    }

    /** @return array{0: string, 1: CarbonImmutable} */
    public static function dayWindow(): array
    {
        $now = CarbonImmutable::now('UTC');

        return ['d:'.$now->toDateString(), $now->endOfDay()];
    }
}
