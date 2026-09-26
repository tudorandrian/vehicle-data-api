<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\ImportPipeline;
use VehicleData\Core\Importers\LicenceNotAdmitted;
use VehicleData\Core\Importers\SourceRegistry;

final class ImportCommand extends Command
{
    /** @var string */
    protected $signature = 'vehicle:import {source : eea|ro-fleet|wikidata|wmi} {--year=} {--limit=} {--force} {--file= : local file or fixture instead of a download}';

    /** @var string */
    protected $description = 'Import one open-data source with provenance, normalisation and a reject report. '
        .'wmi uses a much higher reject-share tolerance (core.import_reject_share_wmi) because vPIC returns '
        .'6-character WMIs alongside the 3-character ones this catalogue models, and those are always rejected.';

    public function handle(SourceRegistry $sources, ImportPipeline $pipeline): int
    {
        try {
            $source = $sources->get((string) $this->argument('source'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rejectShareKey = $source->key() === 'wmi' ? 'core.import_reject_share_wmi' : 'core.import_reject_share';
        $options = new ImportOptions(
            year: $this->option('year') !== null ? (int) $this->option('year') : null,
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            force: (bool) $this->option('force'),
            file: $this->option('file') ?: null,
            rejectShare: (float) config($rejectShareKey, 0.05),
        );

        try {
            $run = $pipeline->run($source, $options);
        } catch (LicenceNotAdmitted $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['status', 'read', 'written', 'rejected', 'checksum'], [[$run->status, $run->rows_read, $run->rows_written, $run->rows_rejected, $run->file_checksum ?? '-']]);
        foreach ($run->reject_report['summary'] ?? [] as $rule => $n) {
            $this->line("  reject {$rule}: {$n}");
        }

        return $run->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
