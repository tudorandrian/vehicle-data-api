<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Sources;

use League\Csv\Reader;
use RuntimeException;
use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\Downloader;
use VehicleData\Core\Importers\HasFileChecksum;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\NameNormaliser;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Importers\Reject;
use VehicleData\Core\Support\Slug;

/**
 * DRPCIV - Parc auto România (national vehicle fleet by county, category, make and commercial name).
 * Only AUTOTURISM (passenger car) rows feed `ro_fleet` in v1 (kind `car`).
 *
 * The file has one row per (county, category, make, commercial name); the same make/model appears once
 * per county. fetch() pre-aggregates the whole file in memory, keyed by the NORMALISED make/model slug
 * (not the raw MARCA/DESCRIERE_COMERCIALA text): DRPCIV's own raw strings are consistent per make in
 * practice, but NameNormaliser's aliasing (e.g. "MERCEDES BENZ" and "MERCEDES-BENZ" both -> "Mercedes-Benz")
 * means two distinct raw strings can legitimately map to the same catalogue record, and aggregating
 * before normalisation would let one raw spelling's total silently overwrite the other's in the writer
 * instead of the two summing. Aggregating by slug fixes that, while still yielding exactly one RawRow per
 * distinct RAW (make) and (make, model) pair - as DRPCIV actually spells them - so provenance keeps one
 * row per distinct raw pair (source_ref carries the raw text) and the reject-share threshold in
 * ImportPipeline applies to distinct raw names, not to the ~142k underlying AUTOTURISM county rows.
 * Each yielded row's TOTAL is already the full merged count for its normalised group, so re-imports and
 * multiple raw spellings of the same make/model all write the same final number (FleetWriter sets rather
 * than sums), keeping the import idempotent regardless of how many raw variants contributed to it.
 */
final class RoFleetSource implements DataSource, HasFileChecksum
{
    private ?string $checksum = null;

    public function key(): string
    {
        return 'ro-fleet';
    }

    public function name(): string
    {
        return 'DRPCIV - Parc auto România (vehicle fleet by county, category, make and commercial name)';
    }

    public function url(): string
    {
        return 'https://data.gov.ro/dataset/parc-auto-romania';
    }

    public function licence(): Licence
    {
        return Licence::oglRou();
    }

    public function fetch(ImportOptions $options): iterable
    {
        $year = $options->year ?? throw new RuntimeException('--year is required for ro-fleet (reference year of the file, e.g. 2025).');
        if ($options->file !== null) {
            $path = $options->file;
            $this->checksum = hash_file('sha256', $path) ?: null;
        } else {
            $url = config("core.ro_fleet_resources.{$year}") ?? throw new RuntimeException("No data.gov.ro resource configured for {$year} (core.ro_fleet_resources).");
            ['path' => $path, 'sha256' => $this->checksum] = Downloader::fetch($url, 'ro-fleet', "parc-auto-{$year}.csv");
        }

        $csv = Reader::createFromPath($path);
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);
        $csv->skipInputBOM();

        // Distinct raw MARCA -> normalised make name (or Reject), computed once per distinct raw value.
        /** @var array<string, string|Reject> $rawMakes */
        $rawMakes = [];
        // Distinct raw "MARCA|DESCRIERE_COMERCIALA" -> [makeName, modelName] (or Reject).
        /** @var array<string, array{0:string,1:string}|Reject> $rawPairs */
        $rawPairs = [];
        // make_slug -> aggregated count, summed across every raw MARCA spelling that normalises to it.
        /** @var array<string, int> $makeTotals */
        $makeTotals = [];
        // "make_slug|model_slug" -> aggregated count.
        /** @var array<string, int> $modelTotals */
        $modelTotals = [];

        foreach ($csv->getRecords() as $r) {
            if (trim((string) ($r['CATEGORIE_NATIONALA'] ?? '')) !== 'AUTOTURISM') {
                continue;
            }
            $rawMake = trim((string) ($r['MARCA'] ?? ''));
            $rawModel = trim((string) ($r['DESCRIERE_COMERCIALA'] ?? ''));
            $n = (int) ($r['TOTAL_VEHICULE'] ?? 0);
            if ($rawMake === '' || $n <= 0) {
                continue;
            }

            if (! array_key_exists($rawMake, $rawMakes)) {
                $rawMakes[$rawMake] = NameNormaliser::make($rawMake);
            }
            $makeNorm = $rawMakes[$rawMake];
            if (! ($makeNorm instanceof Reject)) {
                $makeSlug = Slug::make($makeNorm);
                $makeTotals[$makeSlug] = ($makeTotals[$makeSlug] ?? 0) + $n;
            }

            if ($rawModel === '') {
                continue;
            }
            $pairKey = $rawMake.'|'.$rawModel;
            if (! array_key_exists($pairKey, $rawPairs)) {
                $rawPairs[$pairKey] = $makeNorm instanceof Reject ? $makeNorm : (
                    ($modelNorm = NameNormaliser::model($rawModel)) instanceof Reject ? $modelNorm : [$makeNorm, $modelNorm]
                );
            }
            $pair = $rawPairs[$pairKey];
            if (! ($pair instanceof Reject)) {
                [$mkNorm, $mdNorm] = $pair;
                $key = Slug::make($mkNorm).'|'.Slug::make($mkNorm.' '.$mdNorm);
                $modelTotals[$key] = ($modelTotals[$key] ?? 0) + $n;
            }
        }

        $i = 0;
        foreach ($rawMakes as $rawMake => $makeNorm) {
            if ($options->limit !== null && $i++ >= $options->limit) {
                return;
            }
            $count = $makeNorm instanceof Reject ? 0 : $makeTotals[Slug::make($makeNorm)];
            yield new RawRow(['MARCA' => $rawMake, 'DESCRIERE_COMERCIALA' => '', 'TOTAL' => $count, 'AN' => $year], "{$year}|{$rawMake}|");
        }
        foreach ($rawPairs as $pairKey => $pair) {
            if ($options->limit !== null && $i++ >= $options->limit) {
                return;
            }
            [$rawMake, $rawModel] = explode('|', $pairKey, 2);
            $count = 0;
            if (! ($pair instanceof Reject)) {
                [$mkNorm, $mdNorm] = $pair;
                $count = $modelTotals[Slug::make($mkNorm).'|'.Slug::make($mkNorm.' '.$mdNorm)];
            }
            yield new RawRow(['MARCA' => $rawMake, 'DESCRIERE_COMERCIALA' => $rawModel, 'TOTAL' => $count, 'AN' => $year], "{$year}|{$rawMake}|{$rawModel}");
        }
    }

    public function map(RawRow $row): ?DomainRow
    {
        $make = NameNormaliser::make((string) $row->data['MARCA']);
        if ($make instanceof Reject) {
            return $row->reject('make:'.$make->rule);
        }
        $attrs = [
            'level' => 'make', 'make_name' => $make, 'make_slug' => Slug::make($make), 'make_raw' => trim((string) $row->data['MARCA']),
            'count' => (int) $row->data['TOTAL'], 'year' => (int) $row->data['AN'], 'national_category_code' => 'autoturism',
        ];
        if ((string) $row->data['DESCRIERE_COMERCIALA'] !== '') {
            $model = NameNormaliser::model((string) $row->data['DESCRIERE_COMERCIALA']);
            if ($model instanceof Reject) {
                return $row->reject('model:'.$model->rule);
            }
            $attrs['level'] = 'model';
            $attrs['model_name'] = $model;
            $attrs['model_slug'] = Slug::make($make.' '.$model);
            $attrs['model_raw'] = trim((string) $row->data['DESCRIERE_COMERCIALA']);
        }

        return new DomainRow('fleet', $attrs, $row->ref);
    }

    public function naturalKey(DomainRow $row): string
    {
        return 'ro-fleet|'.$row->attributes['year'].'|'.($row->attributes['model_slug'] ?? $row->attributes['make_slug']);
    }

    public function fileChecksum(): ?string
    {
        return $this->checksum;
    }
}
