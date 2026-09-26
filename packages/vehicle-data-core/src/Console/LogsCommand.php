<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tails the JSON `api.request` lines written by RecordUsage/LogRequest to
 * `storage/logs/laravel-*.log`, filtered by status class/code (`--status`,
 * e.g. `5xx` or `503`) and age (`--since`, e.g. `1h`); `prune` deletes dated
 * `imports-*.log`/`status-*.log` files older than `logging.channels.daily.days`
 * (Monolog rotates `laravel-*.log` itself, this command never touches it).
 */
final class LogsCommand extends Command
{
    /** @var string */
    protected $signature = 'vehicle:logs {action : tail|prune} {--status=} {--since=1h} {--lines=100}';

    /** @var string */
    protected $description = 'Tail the JSON api.request log lines, or prune dated import/status log files.';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'tail' => $this->tail(),
            'prune' => $this->prune(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error('Unknown action; expected "tail" or "prune".');

        return self::FAILURE;
    }

    private function prune(): int
    {
        $days = (int) config('logging.channels.daily.days', 14);
        $cutoff = now()->subDays($days)->toDateString();
        $logsPath = (string) config('core.logs_path', storage_path('logs'));

        $n = 0;
        foreach (['imports-*.log', 'status-*.log'] as $pattern) {
            foreach (glob($logsPath.'/'.$pattern) ?: [] as $file) {
                if (preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m) !== 1) {
                    continue;
                }
                if ($m[1] < $cutoff) {
                    unlink($file);
                    $n++;
                }
            }
        }
        $this->info("Deleted {$n} log file(s) older than {$days} days.");

        return self::SUCCESS;
    }

    private function tail(): int
    {
        $cutoff = now()->subSeconds($this->parseSince((string) $this->option('since')));
        $status = $this->option('status');
        $limit = (int) $this->option('lines');

        // Oldest file first: each file's own lines are already chronological
        // (append-only), so processing files in date order — not
        // newest-first — keeps the combined stream chronological too. That
        // matters once `--since` spans more than one daily file (e.g. just
        // after midnight): iterating newest-first previously interleaved
        // "today, in order" then "yesterday, in order", so slicing the last
        // N entries off the END of that list returned yesterday's tail
        // instead of the most recent lines overall.
        $logsPath = (string) config('core.logs_path', storage_path('logs'));
        $files = glob($logsPath.'/laravel-*.log') ?: [];
        sort($files);

        // A bounded ring buffer, not `file()` + `array_slice()`: each file is
        // streamed line-by-line (`fgets`) so a large log is never loaded
        // into memory whole, and only the `--lines` most recent matches are
        // ever held at once.
        $matched = [];
        foreach ($files as $file) {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }

            try {
                while (($raw = fgets($handle)) !== false) {
                    $line = trim($raw);
                    if ($line === '') {
                        continue;
                    }

                    /** @var array<string, mixed>|null $decoded */
                    $decoded = json_decode($line, true);
                    if (! is_array($decoded) || ($decoded['message'] ?? null) !== 'api.request') {
                        continue;
                    }

                    $lineStatus = (int) (($decoded['context'] ?? [])['status'] ?? 0);
                    $datetime = isset($decoded['datetime']) ? Carbon::parse((string) $decoded['datetime']) : null;

                    if ($datetime === null || $datetime->lt($cutoff)) {
                        continue;
                    }
                    if ($status !== null && ! $this->matchesStatus($lineStatus, (string) $status)) {
                        continue;
                    }

                    $matched[] = $line;
                    if (count($matched) > $limit) {
                        array_shift($matched);
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        foreach ($matched as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function matchesStatus(int $status, string $filter): bool
    {
        if (preg_match('/^(\d)xx$/', $filter, $m) === 1) {
            return intdiv($status, 100) === (int) $m[1];
        }

        return $status === (int) $filter;
    }

    private function parseSince(string $since): int
    {
        if (preg_match('/^(\d+)([mhd])$/', $since, $m) !== 1) {
            return 3600;
        }

        $amount = (int) $m[1];

        return match ($m[2]) {
            'm' => $amount * 60,
            'h' => $amount * 3600,
            default => $amount * 86400,
        };
    }
}
