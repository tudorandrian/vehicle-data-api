<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Sources;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\EuroNorm;
use VehicleData\Core\Importers\FuelCode;
use VehicleData\Core\Importers\HasFileChecksum;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\NameNormaliser;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Importers\Reject;
use VehicleData\Core\Support\Slug;
use VehicleData\Core\Taxonomies\TaxonomyDefinitions;

final class EeaSource implements DataSource, HasFileChecksum
{
    public const ENDPOINT = 'https://discodata.eea.europa.eu/sql';

    public const PAGE = 1000;

    private ?string $checksum = null;

    public function key(): string
    {
        return 'eea';
    }

    public function name(): string
    {
        return 'EEA - Monitoring of CO2 emissions from passenger cars';
    }

    public function url(): string
    {
        return 'https://www.eea.europa.eu/en/datahub/datahubitem-view/fa8b1229-3db6-495d-b18e-9c9b3267c02b';
    }

    public function licence(): Licence
    {
        return Licence::eea();
    }

    public function fetch(ImportOptions $options): iterable
    {
        $hash = hash_init('sha256');
        $n = 0;
        if ($options->file !== null) {
            $json = File::get($options->file);
            hash_update($hash, $json);
            foreach ((array) (json_decode($json, true)['results'] ?? []) as $row) {
                if ($options->limit !== null && $n++ >= $options->limit) {
                    break;
                }
                yield new RawRow($row, (string) $row['ID']);
            }
            $this->checksum = hash_final($hash);

            return;
        }

        $year = $options->year ?? throw new RuntimeException('--year is required for eea (e.g. 2024).');
        $table = config("core.eea_tables.{$year}") ?? throw new RuntimeException("No Discodata table configured for {$year} (core.eea_tables).");
        $country = (string) config('core.eea_country', 'RO');
        // core.eea_country is interpolated directly into the SQL string sent to Discodata (the endpoint
        // has no parameterised query support), so it must be validated as a bare two-letter uppercase
        // country code before it ever reaches the query - this is the only guard against SQL injection
        // through a misconfigured CORE_EEA_COUNTRY value.
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new RuntimeException("Invalid core.eea_country [{$country}]: expected a two-letter uppercase ISO country code (e.g. RO).");
        }
        $sql = "SELECT ID, MS, Mk, Cn, Mh, Man, Ct, Ft, Fm, [Ec (cm3)], [Ep (KW)], [M (kg)], [Ewltp (g/km)], [Enedc (g/km)], [Z (Wh/km)], Ech, Year, TAN, T, Va, Ve, Fc FROM [CO2Emission].[latest].[{$table}] WHERE MS='{$country}' ORDER BY ID";
        $dir = storage_path('app/imports/eea/'.now()->toDateString());
        File::ensureDirectoryExists($dir);

        for ($page = 1; ; $page++) {
            $res = Http::timeout(120)->retry(3, 2000)->get(self::ENDPOINT, ['query' => $sql, 'p' => $page, 'nrOfHits' => self::PAGE])->throw();
            $body = $res->body();
            File::put("{$dir}/{$table}-p{$page}.json", $body);
            hash_update($hash, $body);
            $data = $res->json();
            if (isset($data['errors'])) {
                throw new RuntimeException('Discodata: '.json_encode($data['errors']));
            }
            $rows = (array) ($data['results'] ?? []);
            foreach ($rows as $row) {
                if ($options->limit !== null && $n >= $options->limit) {
                    $this->checksum = hash_final($hash);

                    return;
                }
                $n++;
                yield new RawRow($row, (string) $row['ID']);
            }
            if (count($rows) < self::PAGE) {
                break;
            }
        }
        $this->checksum = hash_final($hash);
    }

    public function map(RawRow $row): ?DomainRow
    {
        $d = $row->data;
        $make = NameNormaliser::make((string) ($d['Mk'] ?? ''));
        if ($make instanceof Reject) {
            return $row->reject('make:'.$make->rule);
        }
        $model = NameNormaliser::model((string) ($d['Cn'] ?? ''));
        if ($model instanceof Reject) {
            return $row->reject('model:'.$model->rule);
        }
        $fuel = FuelCode::fromEea((string) ($d['Ft'] ?? ''));
        if ($fuel === null) {
            return $row->reject('fuel');
        }
        $cat = strtolower(trim((string) ($d['Ct'] ?? '')));
        if (! in_array($cat, TaxonomyDefinitions::all()['eu_category']['terms'], true)) {
            return $row->reject('eu_category');
        }

        $int = static fn (string $k): ?int => isset($d[$k]) && $d[$k] !== '' ? (int) round((float) $d[$k]) : null;
        $str = static fn (string $k): ?string => isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;
        $euro = EuroNorm::fromStage($str('Ech'));
        $makeSlug = Slug::make($make);
        $modelSlug = Slug::make($make.' '.$model);
        $naturalKey = implode('|', ['eea', $makeSlug, $modelSlug, $fuel, $cat, $int('Ec (cm3)') ?? '-', $int('Ep (KW)') ?? '-', $euro ?? '-']);

        $spec = array_filter([
            'euro_stage_raw' => $str('Ech'), 'fuel_mode' => $str('Fm'), 'co2_nedc' => $int('Enedc (g/km)'), 'energy_wh_km' => $int('Z (Wh/km)'),
            'type_approval_number' => $str('TAN'), 'type_code' => $str('T'), 'variant_code' => $str('Va'), 'version_code' => $str('Ve'),
            'fuel_consumption_l_100km' => isset($d['Fc']) && $d['Fc'] !== '' ? round((float) $d['Fc'], 1) : null,
        ], static fn ($v) => $v !== null);

        return new DomainRow('variant', [
            'make_name' => $make, 'make_slug' => $makeSlug, 'make_raw' => trim((string) ($d['Mk'] ?? '')),
            'model_name' => $model, 'model_slug' => $modelSlug, 'model_raw' => trim((string) ($d['Cn'] ?? '')), 'natural_key' => $naturalKey,
            'fuel_code' => $fuel, 'eu_category_code' => $cat, 'euro_norm_code' => $euro,
            'engine_cc' => $int('Ec (cm3)'), 'power_kw' => $int('Ep (KW)'), 'mass_kg' => $int('M (kg)'), 'co2_wltp' => $int('Ewltp (g/km)'),
            'year_from' => $int('Year'), 'year_to' => null, 'specifications' => $spec === [] ? null : $spec,
        ], $row->ref);
    }

    public function naturalKey(DomainRow $row): string
    {
        return $row->attributes['natural_key'];
    }

    public function fileChecksum(): ?string
    {
        return $this->checksum;
    }
}
