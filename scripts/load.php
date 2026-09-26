#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Closed-loop load generator: N virtual users, each sending one request, waiting a random
 * think time, then the next. Measures throughput and latency percentiles per route.
 *
 *   LOAD_KEY=vd_live_… php scripts/load.php --base=http://localhost:8087 --users=100 --seconds=60 --think=200-1000
 *
 * Prefer the LOAD_KEY environment variable over --key=... — a --key=... argument lands in
 * this process's argv, visible to anyone who can run `ps` on the host or container while it
 * runs; LOAD_KEY does not. --key is still accepted if LOAD_KEY is unset.
 *
 * The mix mirrors an integrator's traffic: dropdown chains, variant lists, VIN decodes.
 * Needs ext-curl. Prints one summary; exit code 1 when any request failed (non-2xx/3xx).
 *
 * Notes on reading the numbers:
 * - rps is requests-completed / elapsed-wall-time over the whole run, including the ramp-up
 *   (users start at random offsets in [0, 1s), not all at once) and the drain (the deadline
 *   stops new requests, but in-flight ones are still awaited before the loop exits) — both
 *   pull the reported rps slightly below true steady-state throughput, more so on a short
 *   --seconds value or a high --think range where a handful of stragglers are a bigger share
 *   of the total.
 * - Percentiles are nearest-rank on the sorted sample: index = floor(count * p), i.e. p95 is
 *   "at least 95% of requests were this fast or faster", not an interpolated percentile.
 * - Redirects are not followed (CURLOPT_FOLLOWLOCATION is unset): a 3xx response itself is
 *   what gets measured and counted as success (only < 200 or >= 400 counts as a failure), not
 *   whatever it redirects to.
 */
$opts = getopt('', ['base:', 'key:', 'users::', 'seconds::', 'think::', 'help']);
// getopt() types a repeated flag as an array (last value wins here); a single occurrence is
// the plain string|false PHPStan otherwise refuses to (string) cast directly.
$optStr = static fn (mixed $v): string => is_array($v) ? (string) end($v) : (string) $v;
$envKey = getenv('LOAD_KEY');
$key = isset($opts['key']) ? $optStr($opts['key']) : ($envKey !== false ? $envKey : '');
if (isset($opts['help']) || ! isset($opts['base']) || $key === '') {
    fwrite(STDERR, "usage: load.php --base=URL [--key=KEY, or set \$LOAD_KEY] [--users=100] [--seconds=60] [--think=MIN-MAX ms]\n");
    exit(2);
}
$base = rtrim($optStr($opts['base']), '/');
$users = max(1, (int) ($opts['users'] ?? 100));
$seconds = max(1, (int) ($opts['seconds'] ?? 60));
[$thinkMin, $thinkMax] = array_map('intval', explode('-', $optStr($opts['think'] ?? '200-1000')) + [1 => 0]);
$thinkMax = max($thinkMin, $thinkMax);

$mix = [
    ['/v1/makes?per_page=100', 20], ['/v1/makes/dacia/models', 15], ['/v1/makes/volkswagen/models', 5],
    ['/v1/models/dacia-duster/variants?per_page=50', 15], ['/v1/models/dacia-duster/variants?fuel=diesel&sort=-power_kw', 5],
    ['/v1/taxonomies/fuel', 10], ['/v1/vin/UU1HSDADG45678901', 15], ['/v1/vin/WVWZZZ1KZAW000001', 5],
    ['/v1/manufacturers', 5], ['/v1/makes/dacia?lang=en', 5],
];
$bag = [];
foreach ($mix as [$path, $weight]) {
    $bag = array_merge($bag, array_fill(0, $weight, $path));
}

$multi = curl_multi_init();
$deadline = microtime(true) + $seconds;
$nextAt = array_map(fn () => microtime(true) + mt_rand(0, 1000) / 1000, array_fill(0, $users, null));
$active = [];
$latencies = [];
$statuses = [];
$byPath = [];
$failed = 0;

$start = static function (int $user) use (&$active, $multi, $bag, $base, $key): void {
    $path = $bag[array_rand($bag)];
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$key}", 'Accept: application/json']]);
    curl_multi_add_handle($multi, $ch);
    $active[(int) $ch] = [$user, $path, microtime(true)];
};

$t0 = microtime(true);
while (true) {
    $now = microtime(true);
    if ($now < $deadline) {
        foreach ($nextAt as $user => $at) {
            if ($at !== null && $at <= $now) {
                $nextAt[$user] = null;
                $start($user);
            }
        }
    } elseif ($active === []) {
        break;
    }
    curl_multi_exec($multi, $running);
    if (curl_multi_select($multi, 0.01) === -1) {
        // -1 signals a select() failure, not the normal 0.01s timeout elapsing (that
        // returns 0) — without this, such a failure would spin the loop at full CPU,
        // stealing cycles from the very server this script is measuring.
        usleep(5000);
    }
    while (($info = curl_multi_info_read($multi)) !== false) {
        $ch = $info['handle'];
        [$user, $path, $startedAt] = $active[(int) $ch];
        $ms = (microtime(true) - $startedAt) * 1000;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latencies[] = $ms;
        $statuses[$code] = ($statuses[$code] ?? 0) + 1;
        $byPath[strtok($path, '?')][] = $ms;
        if ($code < 200 || $code >= 400) {
            $failed++;
        }
        curl_multi_remove_handle($multi, $ch);
        unset($active[(int) $ch]);
        $nextAt[$user] = microtime(true) + mt_rand($thinkMin, $thinkMax) / 1000;
    }
}
$elapsed = microtime(true) - $t0;
$pct = static function (array $values, float $p): float {
    sort($values);

    return $values === [] ? 0.0 : $values[(int) min(count($values) - 1, floor(count($values) * $p))];
};
printf("users=%d duration=%.1fs requests=%d rps=%.1f\n", $users, $elapsed, count($latencies), count($latencies) / $elapsed);
printf("latency ms: p50=%.0f p90=%.0f p95=%.0f p99=%.0f max=%.0f\n", $pct($latencies, .5), $pct($latencies, .9), $pct($latencies, .95), $pct($latencies, .99), max($latencies ?: [0]));
ksort($statuses);
echo 'status: '.json_encode($statuses)."\n";
foreach ($byPath as $path => $values) {
    printf("  %-48s n=%-5d p50=%-5.0f p95=%.0f\n", $path, count($values), $pct($values, .5), $pct($values, .95));
}
exit($failed > 0 ? 1 : 0);
