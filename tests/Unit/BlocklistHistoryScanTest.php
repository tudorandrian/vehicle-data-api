<?php

declare(strict_types=1);

/**
 * @return array{0: int, 1: string}
 */
function runInFixtureRepo(string $cmd, string $cwd): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if ($proc === false) {
        throw new RuntimeException("failed to start: $cmd");
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return [$code, ($out ?: '').($err ?: '')];
}

/** Builds a disposable git repo with a blocklisted term hidden only in history metadata, never in a blob. */
function makeFixtureRepo(string $term): string
{
    $tmp = sys_get_temp_dir().'/blocklist-fixture-'.bin2hex(random_bytes(6));
    mkdir($tmp);
    mkdir($tmp.'/scripts');
    file_put_contents($tmp.'/scripts/blocklist.hashes.json', json_encode([hash('sha256', $term)]));

    runInFixtureRepo('git init -q -b main', $tmp);
    runInFixtureRepo('git config user.email clean@example.com', $tmp);
    runInFixtureRepo('git config user.name "Clean Name"', $tmp);
    file_put_contents($tmp.'/file.txt', "hello world\n");
    runInFixtureRepo('git add file.txt', $tmp);
    runInFixtureRepo('git commit -q -m "initial commit"', $tmp);

    return $tmp;
}

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

$scriptPath = static fn (): string => realpath(__DIR__.'/../../scripts/blocklist.php');

it('scan-history catches a blocklisted term hidden only in a commit message', function () use ($scriptPath): void {
    $tmp = makeFixtureRepo('zzblockedmsg');
    try {
        runInFixtureRepo('git commit -q --allow-empty -m "mentions zzblockedmsg here"', $tmp);
        $cmd = 'php '.escapeshellarg($scriptPath()).' scan-history';
        putenv('BLOCKLIST_ROOT='.$tmp);
        [$code, $out] = runInFixtureRepo($cmd.' 2>&1', $tmp);
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(1)->and($out)->toContain('message/identity');
    } finally {
        removeDirectory($tmp);
    }
});

it('scan-history catches a blocklisted term hidden only in an author/committer identity', function () use ($scriptPath): void {
    $tmp = makeFixtureRepo('zzblockedauthor');
    try {
        runInFixtureRepo('git config user.name "zzblockedauthor"', $tmp);
        file_put_contents($tmp.'/other.txt', "more\n");
        runInFixtureRepo('git add other.txt', $tmp);
        runInFixtureRepo('git commit -q -m "second commit"', $tmp);
        $cmd = 'php '.escapeshellarg($scriptPath()).' scan-history';
        putenv('BLOCKLIST_ROOT='.$tmp);
        [$code, $out] = runInFixtureRepo($cmd.' 2>&1', $tmp);
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(1)->and($out)->toContain('message/identity');
    } finally {
        removeDirectory($tmp);
    }
});

it('scan-history catches a blocklisted term hidden only in a ref name', function () use ($scriptPath): void {
    $tmp = makeFixtureRepo('zzblockedref');
    try {
        runInFixtureRepo('git branch zzblockedref', $tmp);
        $cmd = 'php '.escapeshellarg($scriptPath()).' scan-history';
        putenv('BLOCKLIST_ROOT='.$tmp);
        [$code, $out] = runInFixtureRepo($cmd.' 2>&1', $tmp);
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(1)->and($out)->toContain('(ref name)');
    } finally {
        removeDirectory($tmp);
    }
});

it('scan-history stays clean when history has no blocklisted term anywhere', function () use ($scriptPath): void {
    $tmp = makeFixtureRepo('zzunused');
    try {
        $cmd = 'php '.escapeshellarg($scriptPath()).' scan-history';
        putenv('BLOCKLIST_ROOT='.$tmp);
        [$code] = runInFixtureRepo($cmd.' 2>&1', $tmp);
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(0);
    } finally {
        removeDirectory($tmp);
    }
});

it('scan-history exits 2 when git fails instead of reporting a clean history', function () use ($scriptPath): void {
    // No `git init`: every git call fails, so nothing at all could be scanned.
    $tmp = sys_get_temp_dir().'/blocklist-norepo-'.bin2hex(random_bytes(6));
    mkdir($tmp);
    mkdir($tmp.'/scripts');
    file_put_contents($tmp.'/scripts/blocklist.hashes.json', json_encode([hash('sha256', 'zzunused')]));
    try {
        putenv('BLOCKLIST_ROOT='.$tmp);
        [$code, $out] = runInFixtureRepo('php '.escapeshellarg($scriptPath()).' scan-history 2>&1', $tmp);
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(2)->and($out)->toContain('failed');
    } finally {
        removeDirectory($tmp);
    }
});

it('scan-history exits 2 when git log fails or returns no records although the history has commits', function (string $logBehaviour) use ($scriptPath): void {
    $tmp = makeFixtureRepo('zzunused');
    // A git wrapper that answers every command normally except `log`, which it breaks.
    $fakeGit = $tmp.'/fake-git.sh';
    file_put_contents($fakeGit, "#!/bin/sh\nif [ \"\$1\" = log ]; then $logBehaviour; fi\nexec git \"\$@\"\n");
    chmod($fakeGit, 0755);
    try {
        putenv('BLOCKLIST_ROOT='.$tmp);
        putenv('BLOCKLIST_GIT='.$fakeGit);
        [$code, $out] = runInFixtureRepo('php '.escapeshellarg($scriptPath()).' scan-history 2>&1', $tmp);
        putenv('BLOCKLIST_GIT');
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(2, $out);
    } finally {
        removeDirectory($tmp);
    }
})->with([
    'an empty log' => 'exit 0',
    'a failed log' => 'echo "fatal: invalid --pretty format" >&2; exit 128',
]);

it('scan-history reports how many blobs, commit messages and identities, and refs it scanned', function () use ($scriptPath): void {
    $tmp = makeFixtureRepo('zzunused');
    try {
        runInFixtureRepo('git commit -q --allow-empty -m "second"', $tmp);
        putenv('BLOCKLIST_ROOT='.$tmp);
        [$code, $out] = runInFixtureRepo('php '.escapeshellarg($scriptPath()).' scan-history 2>&1', $tmp);
        putenv('BLOCKLIST_ROOT');

        expect($code)->toBe(0)
            ->and($out)->toContain('scanned 1 blob(s), 2 commit(s) (messages and author/committer identities), 1 ref(s)');
    } finally {
        removeDirectory($tmp);
    }
});
