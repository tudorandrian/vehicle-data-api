<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use VehicleData\Core\Usage\AggregateDailyUsage;
use VehicleData\Core\Usage\PruneRequests;
use VehicleData\Core\Usage\PurgeRequestIps;

/**
 * `vehicle:usage aggregate` rolls `vd_api_requests` up into
 * `vd_api_usage_daily` for one day (see {@see AggregateDailyUsage});
 * `purge-ip` nulls old IPs (see {@see PurgeRequestIps}); `prune` deletes raw
 * request rows past core.request_retention_days (see {@see PruneRequests});
 * `report` renders a Markdown usage table straight from the daily aggregate.
 */
final class UsageCommand extends Command
{
    /** @var string */
    protected $signature = 'vehicle:usage {action : aggregate|purge-ip|prune|report} {--date=yesterday} {--client=} {--since=30d}';

    /** @var string */
    protected $description = 'Aggregate daily API usage, purge old request IPs, prune old requests, or print a usage report.';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'aggregate' => $this->aggregate(),
            'purge-ip' => $this->purgeIp(),
            'prune' => $this->prune(),
            'report' => $this->report(),
            default => $this->unknownAction(),
        };
    }

    private function aggregate(): int
    {
        $date = CarbonImmutable::parse((string) $this->option('date'));
        $clients = AggregateDailyUsage::forDate($date);
        $this->info("Aggregated usage for {$date->toDateString()}: {$clients} client(s).");

        return self::SUCCESS;
    }

    private function purgeIp(): int
    {
        $rows = PurgeRequestIps::run();
        $this->info("Purged the ip on {$rows} request(s).");

        return self::SUCCESS;
    }

    private function prune(): int
    {
        $days = (int) config('core.request_retention_days', 90);
        $n = PruneRequests::run();
        $this->info("Pruned {$n} request(s) older than {$days} days.");

        return self::SUCCESS;
    }

    private function report(): int
    {
        $since = $this->parseSince((string) $this->option('since'));
        $client = $this->option('client');

        // Plain DB::table() (stdClass rows): the joined/aggregated columns
        // (client_name, days, worst_p95, …) aren't real ApiUsageDaily columns.
        $rows = DB::table('vd_api_usage_daily')
            ->join('vd_api_clients', 'vd_api_clients.id', '=', 'vd_api_usage_daily.client_id')
            ->where('vd_api_usage_daily.date', '>=', $since->toDateString())
            ->when($client !== null, fn ($q) => $q->where(function ($q2) use ($client): void {
                $q2->where('vd_api_clients.name', $client)->orWhere('vd_api_clients.id', $client);
            }))
            ->selectRaw('vd_api_clients.name AS client_name, COUNT(*) AS days, SUM(vd_api_usage_daily.requests) AS requests, '
                .'SUM(vd_api_usage_daily.errors) AS errors, MAX(vd_api_usage_daily.p95_ms) AS worst_p95')
            ->groupBy('vd_api_clients.id', 'vd_api_clients.name')
            ->orderBy('vd_api_clients.name')
            ->get();

        $this->line('| client | days | requests | errors | worst p95 ms |');
        $this->line('|---|---|---|---|---|');
        foreach ($rows as $row) {
            // Two writes, not one $this->line() per row: Laravel's
            // expectsOutputToContain() mocks Command::$output so that only
            // the FIRST expectation whose predicate matches a given
            // doWrite() call ever fires for it (confirmed against
            // Mockery directly) - a single-line row would let the
            // client-name expectation silently swallow the "| 12 |"
            // (requests) one whenever both substrings share that one
            // line, so each cell group is written separately.
            $this->output->write("| {$row->client_name} |", false);
            $this->output->write(" {$row->days} | {$row->requests} | {$row->errors} | {$row->worst_p95} |", true);
        }

        return self::SUCCESS;
    }

    private function parseSince(string $since): CarbonImmutable
    {
        $days = preg_match('/^(\d+)d$/', $since, $m) === 1 ? (int) $m[1] : 30;

        return CarbonImmutable::now()->subDays($days);
    }

    private function unknownAction(): int
    {
        $this->error('Unknown action; expected "aggregate", "purge-ip", "prune" or "report".');

        return self::FAILURE;
    }
}
