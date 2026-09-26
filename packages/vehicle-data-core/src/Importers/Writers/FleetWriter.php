<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Writers;

use Illuminate\Database\Eloquent\Model;
use VehicleData\Core\Contracts\RecordWriter;
use VehicleData\Core\Importers\CatalogueIdentity;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Models\Source;

/**
 * Sets (not sums) the fleet count carried by the row: RoFleetSource pre-aggregates by normalised
 * make/model slug and hands every row for the same make/model the same already-summed total, so writing
 * more than one row for it (one per distinct raw spelling in the source file, or a plain re-import) must
 * be idempotent rather than compounding. "Sum on write" would double-count every re-import and every
 * make with more than one raw spelling in DRPCIV's own data.
 */
final class FleetWriter implements RecordWriter
{
    public function type(): string
    {
        return 'fleet';
    }

    /** @return list<Model> */
    public function write(DomainRow $row, Source $source, \DateTimeInterface $retrievedAt): array
    {
        $a = $row->attributes;
        $make = CatalogueIdentity::make($source, $a['make_raw'], $a['make_name'], 'car', $retrievedAt);

        if ($a['level'] === 'make') {
            $make->forceFill(['ro_fleet_count' => $a['count'], 'ro_fleet_year' => $a['year']])->save();

            return [$make];
        }

        $model = CatalogueIdentity::model($source, $make, $a['model_raw'], $a['model_name'], $retrievedAt);
        $model->forceFill(['ro_fleet_count' => $a['count'], 'ro_fleet_year' => $a['year']])->save();

        return [$model];
    }
}
