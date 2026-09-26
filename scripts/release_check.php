#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Release gate A (mechanical): prints a checklist and exits 1 if any check fails.
 *
 * Usage:
 *   php scripts/release_check.php vX.Y.Z            # on a workstation: artisan/composer run in the Docker `app` container
 *   php scripts/release_check.php vX.Y.Z --direct   # in CI: artisan/composer run with this PHP, against an already migrated and seeded database
 *
 * Runs on the host PHP (8.3 is enough); only git, and optionally gh, gitleaks and docker, are shelled out to.
 * A check that cannot be run on this machine (no gitleaks binary and no Docker, no authenticated gh) is
 * reported as `[?] … (manual: …)`: it does not fail the run, and it must be confirmed by hand or by CI.
 */
$usage = "usage: php scripts/release_check.php vX.Y.Z [--direct]\n";
$version = $argv[1] ?? '';
if (preg_match('/^v(\d+\.\d+\.\d+)$/', $version, $m) !== 1) {
    fwrite(STDERR, $usage);
    exit(2);
}
$semver = $m[1];
$direct = in_array('--direct', array_slice($argv, 2), true);

$root = dirname(__DIR__);
chdir($root);

const GITLEAKS_IMAGE = 'zricethezav/gitleaks:v8.30.1';
const MAX_BYTES = 102400;
/** Generated, reproducibility-critical lock files: exempt from the size gate (same rule as .githooks/pre-commit and .gitleaks.toml). */
const SIZE_EXEMPT = ['composer.lock', 'package-lock.json'];
const BINARY_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'zip', 'sqlite', 'sqlite3', 'bin'];

/** @return array{0:int,1:string} exit code and combined output */
function run(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd.' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

function available(string $cmd): bool
{
    return run($cmd)[0] === 0;
}

$php = escapeshellarg(PHP_BINARY);
$inApp = static fn (string $cmd): string => $direct ? $cmd : 'docker compose exec -T app '.$cmd;

/** @var list<array{0:string,1:'pass'|'fail'|'manual',2:string}> $checks */
$checks = [];
$add = static function (string $name, string $status, string $detail = '') use (&$checks): void {
    $checks[] = [$name, $status, $detail];
};
$tail = static fn (string $output): string => trim(implode(' | ', array_slice(array_filter(explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $output))), -3)));
$scanned = static fn (string $output): string => preg_match('/(\d+) commits scanned/', $output, $n) === 1 ? "{$n[1]} commits scanned" : 'no leaks found';

// 1–2. Hash blocklist over the working tree and every blob reachable from any ref.
[$c, $o] = run("$php scripts/blocklist.php scan .");
$add('blocklist: working tree clean', $c === 0 ? 'pass' : 'fail', $c === 0 ? '' : $tail($o));
[$c, $o] = run("$php scripts/blocklist.php scan-history");
$add('blocklist: full history clean', $c === 0 ? 'pass' : 'fail', $c === 0 ? '' : $tail($o));

// 3. gitleaks over the full history: local binary, else the pinned image through Docker.
if (available('gitleaks version')) {
    [$c, $o] = run('gitleaks git --redact --no-banner --config .gitleaks.toml .');
    $add('gitleaks: full history clean', $c === 0 ? 'pass' : 'fail', $c === 0 ? $scanned($o) : $tail($o));
} elseif (! $direct && available('docker version')) {
    [$c, $o] = run('docker run --rm -v '.escapeshellarg($root.':/repo').' '.GITLEAKS_IMAGE.' git /repo --redact --no-banner --config /repo/.gitleaks.toml');
    $add('gitleaks: full history clean', $c === 0 ? 'pass' : 'fail', $c === 0 ? $scanned($o).' (pinned '.GITLEAKS_IMAGE.')' : $tail($o));
} else {
    $add('gitleaks: full history clean', 'manual', 'gitleaks not available here; the release-check CI job runs it over the full history');
}

// 4. Size and type limits on every tracked file (the pre-commit hook's rules, applied to the whole tree).
[$c, $o] = run('git ls-files');
$tooBig = [];
$binaries = [];
foreach ($c === 0 ? array_filter(explode("\n", $o)) : [] as $file) {
    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), BINARY_EXTENSIONS, true)) {
        $binaries[] = $file;
    }
    if (! in_array($file, SIZE_EXEMPT, true) && is_file($file) && filesize($file) > MAX_BYTES) {
        $tooBig[] = $file.' ('.filesize($file).' bytes)';
    }
}
$add('no tracked file > 100 KB (lock files exempt)', $c === 0 && $tooBig === [] ? 'pass' : 'fail', implode(', ', $tooBig));
$add('no tracked image or binary file', $c === 0 && $binaries === [] ? 'pass' : 'fail', implode(', ', $binaries));

// 5. Every source has an admitted licence, licence URL and attribution; every fixture is named in docs/data-sources.md.
[$c, $o] = run($inApp('php artisan vehicle:sources check'));
$add('sources: licence + attribution + fixtures documented', $c === 0 ? 'pass' : 'fail', $tail($o));

// 6. CHANGELOG has a dated section for this version. On the tag (--direct) the date is required;
// on a workstation gate A runs before the release pull request dates the heading, so an undated
// section is left for the tag run to confirm instead of failing here.
$changelog = is_file('CHANGELOG.md') ? (string) file_get_contents('CHANGELOG.md') : '';
$heading = '/^## \['.preg_quote($semver, '/').'\]';
if (preg_match($heading.' - \d{4}-\d{2}-\d{2}/mu', $changelog) === 1) {
    $add("CHANGELOG has [$semver]", 'pass');
} elseif (! $direct && preg_match($heading.'/m', $changelog) === 1) {
    $add("CHANGELOG has [$semver]", 'manual', 'date not set');
} else {
    $add("CHANGELOG has [$semver]", 'fail', preg_match($heading.'/m', $changelog) === 1 ? 'the heading has no date: ## ['.$semver.'] - YYYY-MM-DD' : '');
}

// 7. The latest ci run on main succeeded.
[$c, $o] = run('gh run list --branch main --workflow ci --limit 1 --json conclusion --jq ".[0].conclusion"');
if ($c !== 0) {
    $add('CI green on main', 'manual', 'gh unavailable or not authenticated: check the latest ci run on main by hand');
} else {
    $add('CI green on main', trim($o) === 'success' ? 'pass' : 'fail', 'latest conclusion: '.(trim($o) === '' ? 'none (still running?)' : trim($o)));
}

// 8. No known vulnerability in the locked Composer dependencies.
[$c, $o] = run($inApp('composer audit --no-interaction'));
$add('composer audit clean', $c === 0 ? 'pass' : 'fail', $tail($o));

$failed = 0;
$manual = 0;
echo "Release check for $version\n";
foreach ($checks as [$name, $status, $detail]) {
    $mark = ['pass' => '[x]', 'fail' => '[ ]', 'manual' => '[?]'][$status];
    $failed += $status === 'fail' ? 1 : 0;
    $manual += $status === 'manual' ? 1 : 0;
    $suffix = $status === 'manual' ? " (manual: $detail)" : ($detail !== '' ? " - $detail" : '');
    echo "$mark $name$suffix\n";
}
echo $failed === 0
    ? 'PASS: '.count($checks)." checks, $manual manual\n"
    : "FAIL: $failed of ".count($checks)." checks failed\n";
exit($failed === 0 ? 0 : 1);
