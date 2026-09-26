<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Writers;

use Illuminate\Database\Eloquent\Model;
use VehicleData\Core\Contracts\RecordWriter;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\Wmi;

final class WmiWriter implements RecordWriter
{
    public function type(): string
    {
        return 'wmi';
    }

    /** @return list<Model> */
    public function write(DomainRow $row, Source $source, \DateTimeInterface $retrievedAt): array
    {
        $a = $row->attributes;
        $wmi = Wmi::query()->updateOrCreate(['code' => $a['code']], [
            'manufacturer_name' => $a['manufacturer_name'], 'country_code' => $a['country_code'] ?? null, 'vehicle_type' => $a['vehicle_type'] ?? null,
            'source_id' => $source->id,
        ]);

        return [$wmi];
    }
}
