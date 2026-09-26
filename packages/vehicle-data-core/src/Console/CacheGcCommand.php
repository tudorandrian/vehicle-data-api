<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The database cache store never deletes expired rows unless the same key is read again; this does, once a day. */
final class CacheGcCommand extends Command
{
    /** @var string */
    protected $signature = 'vehicle:cache {action : gc}';

    /** @var string */
    protected $description = 'Remove expired rows from the database cache table.';

    public function handle(): int
    {
        if ((string) $this->argument('action') !== 'gc') {
            $this->error('Unknown action; expected "gc".');

            return self::INVALID;
        }
        $table = (string) config('cache.stores.database.table', 'cache');
        $connection = config('cache.stores.database.connection');
        $n = DB::connection(is_string($connection) ? $connection : null)
            ->table($table)
            ->where('expiration', '<', now()->timestamp)
            ->delete();
        $this->info("Removed {$n} expired cache row(s).");

        return self::SUCCESS;
    }
}
