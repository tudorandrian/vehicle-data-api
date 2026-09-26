<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Contracts\RecordWriter;
use VehicleData\Core\Contracts\RunAware;
use VehicleData\Core\Models\ImportRun;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Support\AdmittedLicences;

final class ImportPipeline
{
    public const BATCH = 1000;

    /** @param array<string, RecordWriter> $writers keyed by type */
    public function __construct(private readonly array $writers) {}

    public function run(DataSource $source, ImportOptions $options): ImportRun
    {
        $licence = $source->licence();
        if (! AdmittedLicences::admits($licence->id)) {
            // Policy before publication (R12): an inadmissible licence never reaches a run row or
            // the catalogue; `vehicle:sources check` flags it at release time (the second opinion).
            throw new LicenceNotAdmitted($source->key(), $licence->id);
        }
        $sourceRow = Source::query()->updateOrCreate(['key' => $source->key()], [
            'name' => $source->name(), 'licence_id' => $licence->id, 'licence_name' => $licence->name, 'licence_url' => $licence->url,
            'attribution' => $licence->attribution, 'url' => $source->url(),
        ]);
        $run = ImportRun::query()->create(['source_id' => $sourceRow->id, 'started_at' => now(), 'status' => 'running', 'options' => (array) $options]);
        $report = new RejectReport;
        $read = 0;
        $written = 0;
        $retrievedAt = now();

        // The whole run is one atomic unit: if it aborts (reject share exceeded) or
        // fails, every batch flushed so far is rolled back with it — no partial
        // catalogue writes survive a failed/aborted run.
        try {
            DB::transaction(function () use ($source, $options, $sourceRow, $report, $retrievedAt, &$read, &$written): void {
                $batch = [];
                foreach ($source->fetch($options) as $raw) {
                    if ($options->limit !== null && $read >= $options->limit) {
                        break;
                    }
                    $read++;
                    $domain = $source->map($raw);
                    if ($domain === null) {
                        $report->add($raw->ref, $raw->rejectRule ?? 'unmapped', $raw->data);
                    } else {
                        $batch[] = [$domain, $raw];
                    }
                    if ($read >= 100 && $report->count() / $read > $options->rejectShare) {
                        throw new RuntimeException(sprintf('Aborted: %d of %d rows rejected (> %.0f%%).', $report->count(), $read, $options->rejectShare * 100));
                    }
                    if (count($batch) >= self::BATCH) {
                        $written += $this->flush($batch, $sourceRow, $retrievedAt, $report);
                        $batch = [];
                    }
                }
                $written += $this->flush($batch, $sourceRow, $retrievedAt, $report);
                // Writer-level rejects (RowRejected) from that last flush were never checked against the
                // reject-share threshold above — that check only runs per row, before the row's batch is
                // ever flushed. Re-check here so a trailing batch full of writer rejects can still abort.
                if ($read >= 100 && $report->count() / $read > $options->rejectShare) {
                    throw new RuntimeException(sprintf('Aborted: %d of %d rows rejected (> %.0f%%).', $report->count(), $read, $options->rejectShare * 100));
                }
                // Runs once per successful import, after every row is written, still inside
                // this transaction — never for an aborted or failed run (see RunAware).
                foreach ($this->writers as $writer) {
                    if ($writer instanceof RunAware) {
                        $writer->finish($sourceRow);
                    }
                }
            });
            $run->forceFill(['status' => 'succeeded']);
        } catch (Throwable $e) {
            $run->forceFill(['status' => str_starts_with($e->getMessage(), 'Aborted') ? 'aborted' : 'failed', 'reject_report' => $report->toArray() + ['error' => $e->getMessage()]]);
            $run->forceFill(['finished_at' => now(), 'rows_read' => $read, 'rows_written' => 0, 'rows_rejected' => $report->count()])->save();
            throw $e;
        }

        $run->forceFill(['finished_at' => now(), 'rows_read' => $read, 'rows_written' => $written, 'rows_rejected' => $report->count(), 'reject_report' => $report->toArray(), 'file_checksum' => $source instanceof HasFileChecksum ? $source->fileChecksum() : null])->save();

        return $run;
    }

    /** @param list<array{0: DomainRow, 1: RawRow}> $batch */
    private function flush(array $batch, Source $sourceRow, \DateTimeInterface $retrievedAt, RejectReport $report): int
    {
        if ($batch === []) {
            return 0;
        }
        $n = 0;
        DB::transaction(function () use ($batch, $sourceRow, $retrievedAt, $report, &$n): void {
            foreach ($batch as [$domain, $raw]) {
                $writer = $this->writers[$domain->type] ?? throw new RuntimeException("No writer for {$domain->type}");
                try {
                    // Each row gets its own savepoint (a DB::transaction nested inside the batch's own
                    // transaction becomes one): a RowRejected thrown partway through — e.g. after the
                    // writer already resolved/renamed a make but before it resolves the model — must undo
                    // everything that row did, not leave a partial rename or create behind while the row
                    // is reported as rejected.
                    DB::transaction(function () use ($writer, $domain, $sourceRow, $retrievedAt, $raw): void {
                        foreach ($writer->write($domain, $sourceRow, $retrievedAt) as $model) {
                            $this->provenance($model, $sourceRow, $domain->sourceRef, $retrievedAt, $raw);
                        }
                    });
                } catch (RowRejected $e) {
                    $report->add($raw->ref, $e->rule, $raw->data);

                    continue;
                }
                $n++;
            }
        });

        return $n;
    }

    private function provenance(Model $model, Source $source, string $ref, \DateTimeInterface $retrievedAt, RawRow $raw): void
    {
        RecordSource::query()->updateOrCreate(
            ['record_type' => $model->getMorphClass(), 'record_id' => $model->getKey(), 'source_id' => $source->id, 'source_ref' => $ref],
            ['retrieved_at' => $retrievedAt, 'checksum' => hash('sha256', (string) json_encode($raw->data))],
        );
    }
}
