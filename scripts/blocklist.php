#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Hash-based blocklist. The forbidden terms never appear in the repository:
 * only SHA-256 hashes of lower-cased tokens are committed (scripts/blocklist.hashes.json).
 *
 * Usage:
 *   php scripts/blocklist.php add "<term>"          # appends hashes (and the clear text to .blocklist.local)
 *   php scripts/blocklist.php scan <path> [...]     # scans files/directories, exit 1 on any hit
 *   php scripts/blocklist.php scan-staged           # scans the git index (pre-commit)
 *   php scripts/blocklist.php scan-history          # scans every blob, commit message, author/committer
 *                                                    identity and ref name reachable from any ref
 *
 * BLOCKLIST_ROOT overrides the project root (and so the hash file location); tests use it to point
 * at a disposable fixture repository instead of this repository's own working tree and history.
 * BLOCKLIST_GIT overrides the git executable; tests use it to simulate a failing git.
 *
 * Exit codes: 0 clean, 1 a blocklisted token was found, 2 usage error or git failed. scan-history
 * also exits 2 when `git log` yields no commit although `git rev-list --all` counts some, so a
 * scan that could not read the history never passes as a clean one.
 */
$root = getenv('BLOCKLIST_ROOT') ?: dirname(__DIR__);
$hashFile = $root.'/scripts/blocklist.hashes.json';
$localFile = $root.'/.blocklist.local';

/** @return list<string> */
function variants(string $term): array
{
    $t = mb_strtolower(trim($term));
    $set = [$t, str_replace('-', '_', $t), str_replace('_', '-', $t), str_replace([' ', '-', '_'], '', $t), str_replace(' ', '-', $t), str_replace(' ', '_', $t)];

    return array_values(array_unique(array_filter($set, static fn (string $s): bool => $s !== '')));
}

/** @return list<string> */
function loadHashes(string $file): array
{
    if (! is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? array_values(array_map('strval', $data)) : [];
}

/**
 * Tokens and their dot-delimited sub-runs, plus space-joined n-grams of adjacent word tokens
 * (so a multi-word blocklisted term is caught in normal prose — not just in its fused, hyphenated
 * or underscored forms), all lower-cased.
 *
 * @return list<string>
 */
function tokens(string $line): array
{
    $out = [];
    preg_match_all('/[a-z0-9][a-z0-9._-]*[a-z0-9]|[a-z0-9]/', mb_strtolower($line), $m);
    $words = $m[0];
    foreach ($words as $token) {
        $parts = explode('.', $token);
        $n = count($parts);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i; $j < $n; $j++) {
                $out[] = implode('.', array_slice($parts, $i, $j - $i + 1));
            }
        }
    }

    // Space-joined n-grams of adjacent word tokens on the same line (window of up to 4 words).
    $maxWindow = 4;
    $wordCount = count($words);
    for ($i = 0; $i < $wordCount; $i++) {
        $ngram = $words[$i];
        for ($j = $i + 1; $j < min($wordCount, $i + $maxWindow); $j++) {
            $ngram .= ' '.$words[$j];
            $out[] = $ngram;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param  array<string,true>  $hashes
 * @return list<string> each entry "line: hashprefix"
 */
function scanContent(string $content, array $hashes): array
{
    $hits = [];
    foreach (explode("\n", $content) as $no => $line) {
        foreach (tokens($line) as $token) {
            $h = hash('sha256', $token);
            if (isset($hashes[$h])) {
                $hits[] = sprintf('%d: token hash %s', $no + 1, substr($h, 0, 8));
            }
        }
    }

    return $hits;
}

/**
 * Runs git with an argument vector: no shell is involved, so no argument is re-interpreted (on
 * Windows, escapeshellarg() turns `%` into a space, which silently broke `git log --format=%H…`).
 *
 * @param  list<string>  $args
 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
 */
function git(array $args, string $stdin = ''): array
{
    $bin = getenv('BLOCKLIST_GIT') ?: 'git';
    // stdin comes from a temporary file, not a pipe written here: writing a long input into a
    // pipe while git's output pipe fills up unread would deadlock both processes.
    $in = tmpfile();
    if ($in === false) {
        return [127, '', 'cannot create a temporary file'];
    }
    fwrite($in, $stdin);
    rewind($in);
    $proc = proc_open([$bin, ...$args], [0 => $in, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($proc)) {
        return [127, '', "cannot start $bin"];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    fclose($in);

    return [$code, $out, $err];
}

/**
 * git's stdout, or exit 2 with git's error: a git call that fails must never read as a clean scan.
 *
 * @param  list<string>  $args
 */
function gitOrExit(array $args, string $stdin = ''): string
{
    [$code, $out, $err] = git($args, $stdin);
    if ($code !== 0) {
        fwrite(STDERR, 'blocklist: git '.$args[0]." failed (exit $code): ".trim($err)."\n");
        exit(2);
    }

    return $out;
}

/** @return list<string> */
function listFiles(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f): bool => ! in_array($f->getFilename(), ['.git', 'vendor', 'node_modules', 'storage', '.blocklist.local'], true)
    ));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files[] = $f->getPathname();
        }
    }

    return $files;
}

$cmd = $argv[1] ?? 'help';
$hashList = loadHashes($hashFile);
$hashes = array_fill_keys($hashList, true);

switch ($cmd) {
    case 'add':
        $term = $argv[2] ?? '';
        if ($term === '') {
            fwrite(STDERR, "usage: add <term>\n");
            exit(2);
        }
        foreach (variants($term) as $v) {
            $hashList[] = hash('sha256', $v);
        }
        $hashList = array_values(array_unique($hashList));
        sort($hashList);
        file_put_contents($hashFile, json_encode($hashList, JSON_PRETTY_PRINT).PHP_EOL);
        file_put_contents($localFile, mb_strtolower(trim($term)).PHP_EOL, FILE_APPEND);
        echo 'added '.count(variants($term))." variant hash(es)\n";
        exit(0);

    case 'scan':
        $paths = array_slice($argv, 2) ?: [$root];
        $failed = false;
        foreach ($paths as $p) {
            foreach (listFiles($p) as $file) {
                if (realpath($file) === realpath($hashFile)) {
                    continue;
                }
                foreach (scanContent((string) file_get_contents($file), $hashes) as $hit) {
                    echo "$file:$hit\n";
                    $failed = true;
                }
            }
        }
        exit($failed ? 1 : 0);

    case 'scan-staged':
        $staged = array_filter(explode("\n", gitOrExit(['diff', '--cached', '--name-only', '--diff-filter=ACMR'])));
        $failed = false;
        foreach ($staged as $file) {
            if ($file === 'scripts/blocklist.hashes.json') {
                continue;
            }
            $content = gitOrExit(['show', ':'.$file]);
            foreach (scanContent($content, $hashes) as $hit) {
                echo "$file:$hit\n";
                $failed = true;
            }
        }
        exit($failed ? 1 : 0);

    case 'scan-history':
        $commitCount = (int) trim(gitOrExit(['rev-list', '--all', '--count']));
        $named = [];
        foreach (array_filter(explode("\n", gitOrExit(['rev-list', '--all', '--objects']))) as $line) {
            [$sha, $name] = array_pad(explode(' ', $line, 2), 2, '');
            if ($name !== '' && $name !== 'scripts/blocklist.hashes.json') {
                $named[] = [$sha, $name];
            }
        }
        // Every object's type from one batch call, instead of one git process per object.
        $types = explode("\n", gitOrExit(['cat-file', '--batch-check=%(objecttype)'], implode("\n", array_column($named, 0))."\n"));
        $failed = false;
        $blobs = 0;
        foreach ($named as $i => [$sha, $name]) {
            if (($types[$i] ?? '') !== 'blob') {
                continue;
            }
            $blobs++;
            foreach (scanContent(gitOrExit(['cat-file', 'blob', $sha]), $hashes) as $hit) {
                echo "$sha ($name):$hit\n";
                $failed = true;
            }
        }

        // Blobs only cover file content: a term could also be smuggled into a commit message,
        // an author/committer name or email, or a branch/tag name, none of which are blobs.
        $fieldSep = "\x1f";
        $recordSep = "\x1e";
        $format = '%H'.$fieldSep.'%an'.$fieldSep.'%ae'.$fieldSep.'%cn'.$fieldSep.'%ce'.$fieldSep.'%B'.$recordSep;
        $log = gitOrExit(['log', '--all', '--format='.$format]);
        $commits = 0;
        foreach (explode($recordSep, $log) as $record) {
            $record = ltrim($record, "\n");
            if (trim($record) === '') {
                continue;
            }
            [$sha, $authorName, $authorEmail, $committerName, $committerEmail, $body] = array_pad(explode($fieldSep, $record, 6), 6, '');
            if (preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/', $sha) !== 1) {
                fwrite(STDERR, "blocklist: a git log record has no commit id, so the --format was not applied\n");
                exit(2);
            }
            $commits++;
            $content = implode("\n", [$authorName, $authorEmail, $committerName, $committerEmail, $body]);
            foreach (scanContent($content, $hashes) as $hit) {
                echo "commit $sha (message/identity):$hit\n";
                $failed = true;
            }
        }

        if ($commits === 0 && $commitCount > 0) {
            fwrite(STDERR, "blocklist: git log returned no commit although git rev-list --all counts $commitCount; nothing was scanned\n");
            exit(2);
        }

        $refs = gitOrExit(['for-each-ref', '--format=%(refname)']);
        foreach (scanContent($refs, $hashes) as $hit) {
            echo "(ref name):$hit\n";
            $failed = true;
        }

        echo "scanned $blobs blob(s), $commits commit(s) (messages and author/committer identities), ".count(array_filter(explode("\n", $refs)))." ref(s)\n";
        exit($failed ? 1 : 0);

    default:
        echo "usage: add <term> | scan [paths] | scan-staged | scan-history\n";
        exit(2);
}
