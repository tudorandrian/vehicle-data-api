<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use VehicleData\Core\Models\ApiClient;
use VehicleData\Core\Models\ApiUsageDaily;
use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Models\Wmi;

/**
 * A weekly Markdown health snapshot (scheduled in routes/console.php):
 * import freshness, catalogue size, client activity over the last 7 days
 * and the queue's backlog/failure counts.
 */
final class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'vehicle:status';

    /** @var string */
    protected $description = 'Print a weekly Markdown status summary: imports, catalogue, clients and queue.';

    public function handle(): int
    {
        $this->imports();
        $this->catalogue();
        $this->clients();
        $this->queue();

        return self::SUCCESS;
    }

    private function imports(): void
    {
        $this->line('## Imports');
        $this->line('| source | last success | rows written | rejected |');
        $this->line('|---|---|---|---|');
        foreach (Source::query()->orderBy('key')->get() as $source) {
            $run = ImportRun::query()->where('source_id', $source->id)->where('status', 'succeeded')->orderByDesc('finished_at')->first();
            $lastSuccess = $run?->finished_at?->toIso8601String() ?? 'never';
            $this->line("| {$source->key} | {$lastSuccess} | ".($run->rows_written ?? 0).' | '.($run->rows_rejected ?? 0).' |');
        }
        $this->line('');
    }

    private function catalogue(): void
    {
        $this->line('## Catalogue');
        $this->line('| manufacturers | makes | models | variants | wmi |');
        $this->line('|---|---|---|---|---|');
        $this->line('| '.Manufacturer::query()->count().' | '.Make::query()->count().' | '.VehicleModel::query()->count()
            .' | '.Variant::query()->count().' | '.Wmi::query()->count().' |');
        $this->line('');
    }

    private function clients(): void
    {
        $since = now()->subDays(7)->toDateString();
        $active = ApiClient::query()->whereNull('disabled_at')->count();
        $requests = (int) ApiUsageDaily::query()->where('date', '>=', $since)->sum('requests');
        $errors = (int) ApiUsageDaily::query()->where('date', '>=', $since)->sum('errors');

        $this->line('## Clients');
        $this->line("Active clients: {$active}; requests (7d): {$requests}; errors (7d): {$errors}");
        $this->line('');
    }

    private function queue(): void
    {
        $jobs = (int) DB::table('jobs')->count();
        $failed = (int) DB::table('failed_jobs')->count();

        $this->line('## Queue');
        $this->line("Pending jobs: {$jobs}; failed jobs: {$failed}");
    }
}
