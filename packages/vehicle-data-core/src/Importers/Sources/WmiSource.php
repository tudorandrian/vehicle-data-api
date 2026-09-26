<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Sources;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Importers\CountryName;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;

/**
 * NHTSA vPIC - World Manufacturer Identifiers, looked up per known manufacturer/make name.
 *
 * vPIC also returns 6-character WMIs (the second block of a 17-character VIN under 49 CFR
 * 565), which this catalogue does not model - only the 3-character prefix is a manufacturer
 * identifier here. Those rows are rejected (`wmi_length`), so a live `wmi` run's reject share
 * is legitimately high; ImportCommand relaxes the threshold for this source alone via
 * `core.import_reject_share_wmi` instead of treating it as a data-quality failure.
 */
final class WmiSource implements DataSource
{
    public const ENDPOINT = 'https://vpic.nhtsa.dot.gov/api/vehicles/GetWMIsForManufacturer/';

    public function key(): string
    {
        return 'wmi';
    }

    public function name(): string
    {
        return 'NHTSA vPIC - World Manufacturer Identifiers';
    }

    public function url(): string
    {
        return 'https://vpic.nhtsa.dot.gov/api/';
    }

    public function licence(): Licence
    {
        return Licence::usGov();
    }

    public function fetch(ImportOptions $options): iterable
    {
        $n = 0;
        if ($options->file !== null) {
            foreach ((array) (json_decode(File::get($options->file), true)['results'] ?? []) as $r) {
                if ($options->limit !== null && $n++ >= $options->limit) {
                    return;
                }
                yield new RawRow($r, (string) $r['WMI']);
            }

            return;
        }

        $names = Manufacturer::query()->orderBy('name')->pluck('name')
            ->merge(Make::query()->where('kind', 'car')->pluck('name'))
            ->map(fn (string $s): string => Str::lower(Str::ascii($s)))
            ->unique()->values();

        foreach ($names as $name) {
            $res = Http::timeout(60)->retry(3, 2000)->get(self::ENDPOINT.rawurlencode($name), ['format' => 'json'])->throw();
            foreach ((array) ($res->json('Results') ?? []) as $r) {
                if ($options->limit !== null && $n++ >= $options->limit) {
                    return;
                }
                yield new RawRow(['WMI' => $r['WMI'], 'Name' => $r['Name'], 'Country' => $r['Country'], 'VehicleType' => $r['VehicleType']], (string) $r['WMI']);
            }
            usleep(200_000);
        }
    }

    public function map(RawRow $row): ?DomainRow
    {
        $code = strtoupper(trim((string) $row->data['WMI']));
        if (strlen($code) !== 3) {
            return $row->reject('wmi_length');
        }
        if (preg_match('/^[A-Z0-9]{3}$/', $code) !== 1) {
            return $row->reject('wmi_format');
        }
        $name = trim((string) $row->data['Name']);
        if ($name === '') {
            return $row->reject('empty');
        }

        return new DomainRow('wmi', [
            'code' => $code,
            'manufacturer_name' => $name,
            'country_code' => CountryName::toIso($row->data['Country'] ?? null),
            'vehicle_type' => $row->data['VehicleType'] ?? null,
        ], $row->ref);
    }

    public function naturalKey(DomainRow $row): string
    {
        return 'wmi|'.$row->attributes['code'];
    }
}
