<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

use VehicleData\Core\Http\Query\Fields;
use VehicleData\Core\Models\Manufacturer;

final class ManufacturerResource
{
    public const CLASS1 = ['id', 'slug', 'name'];

    public const CLASS2 = ['country_code', 'founded_year', 'parent', 'website'];

    /** Class-3 keys, dotted as they appear once flattened for CSV. */
    private const CLASS3_COLUMNS = ['logo.commons_file', 'logo.licence', 'logo.url'];

    /**
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    public static function make(Manufacturer $m, EnrichmentContext $ctx, array $fields = []): array
    {
        $data = ['id' => $m->public_id, 'slug' => $m->slug, 'name' => $m->name];
        $data = Fields::required($data + ['country_code' => $m->country_code, 'founded_year' => $m->founded_year, 'parent' => $m->parent_slug, 'website' => $m->website], self::CLASS2);
        // A logo is served only as a reference together with its admitted Commons licence; without
        // a licence the whole class-3 object is omitted, never returned with a null licence.
        $data += Fields::optional(['logo' => $m->logo_commons_file === null || $m->logo_licence === null ? null : [
            'commons_file' => $m->logo_commons_file, 'licence' => $m->logo_licence,
            'url' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode($m->logo_commons_file),
        ]]);
        $data = app(Enrichers::class)->apply('manufacturer', $m, $data, $ctx, array_merge(self::CLASS1, self::CLASS2));

        return Fields::sparse($data, $fields, self::CLASS1);
    }

    /**
     * The declared, stable CSV column list for `make()`'s output shape (dotted for the
     * nested class-3 `logo` object), independent of any particular row's data - so a CSV
     * export never goes ragged when one manufacturer has a logo and another doesn't.
     * Respects the same sparse-fieldset selection as `make()`/`Fields::sparse()`.
     *
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
