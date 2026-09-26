<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

use VehicleData\Core\Http\Query\Fields;
use VehicleData\Core\Models\Make;

final class MakeResource
{
    public const CLASS1 = ['id', 'slug', 'name', 'kind'];

    public const CLASS2 = ['manufacturer'];

    /** Class-3 keys, dotted as they appear once flattened for CSV. */
    private const CLASS3_COLUMNS = ['ro_fleet.count', 'ro_fleet.year'];

    /**
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    public static function make(Make $m, EnrichmentContext $ctx, array $fields = []): array
    {
        $data = Fields::required(['id' => $m->public_id, 'slug' => $m->slug, 'name' => $m->name, 'kind' => $m->kind, 'manufacturer' => $m->manufacturer?->slug], self::CLASS2);
        $data += Fields::optional(['ro_fleet' => $m->ro_fleet_count === null ? null : ['count' => $m->ro_fleet_count, 'year' => $m->ro_fleet_year]]);
        $data = app(Enrichers::class)->apply('make', $m, $data, $ctx, array_merge(self::CLASS1, self::CLASS2));

        return Fields::sparse($data, $fields, self::CLASS1);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function csvColumns(array $fields = []): array
    {
        $columns = array_merge(self::CLASS1, self::CLASS2, self::CLASS3_COLUMNS);

        if ($fields === []) {
            return $columns;
        }

        $keep = array_flip(array_merge(self::CLASS1, $fields));

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => isset($keep[$column]) || isset($keep[strstr($column, '.', true) ?: $column]),
        ));
    }
}
