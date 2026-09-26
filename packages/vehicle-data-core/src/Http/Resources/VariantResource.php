<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

use VehicleData\Core\Http\Query\Fields;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\Power;

final class VariantResource
{
    public const CLASS1 = ['id', 'make', 'model', 'fuel', 'eu_category'];

    public const CLASS2 = ['euro_norm', 'engine_cc', 'power_kw', 'power_hp', 'mass_kg', 'co2_wltp', 'year_from', 'year_to'];

    /** Class-1 columns as they appear once flattened for CSV: `fuel`/`eu_category` are `{code,label}` term objects, not scalars. */
    private const CLASS1_CSV_COLUMNS = ['id', 'make', 'model', 'fuel.code', 'fuel.label', 'eu_category.code', 'eu_category.label'];

    /** Class-2 columns, dotted for CSV: `euro_norm` is also a `{code,label}` term object. */
    private const CLASS2_CSV_COLUMNS = ['euro_norm.code', 'euro_norm.label', 'engine_cc', 'power_kw', 'power_hp', 'mass_kg', 'co2_wltp', 'year_from', 'year_to'];

    /** Class-3 keys, dotted as they appear once flattened for CSV. `specifications` is freeform, so CsvResponder JSON-encodes it into a single cell rather than dotting it. */
    private const CLASS3_COLUMNS = ['specifications'];

    /**
     * @param  list<string>  $fields
     * @return array<string,mixed>
     */
    public static function make(Variant $v, EnrichmentContext $ctx, array $fields = []): array
    {
        $lang = $ctx->lang;
        // vd_variants.model_id and vd_models.make_id are required, cascading foreign
        // keys: the relations are never actually null at runtime, but BelongsTo::__get()
        // is typed nullable - narrowed back here for PHPStan/Larastan (see the same note
        // on VehicleModelResource::make()).
        /** @var VehicleModel $model */
        $model = $v->model;
        /** @var Make $make */
        $make = $model->make;
        $data = ['id' => $v->public_id, 'make' => $make->slug, 'model' => $model->slug,
            'fuel' => Labels::term('fuel', $v->fuel_code, $lang), 'eu_category' => Labels::term('eu_category', $v->eu_category_code, $lang)];
        $data = Fields::required($data + ['euro_norm' => Labels::term('euro_norm', $v->euro_norm_code, $lang), 'engine_cc' => $v->engine_cc, 'power_kw' => $v->power_kw,
            'power_hp' => Power::hp($v->power_kw), 'mass_kg' => $v->mass_kg, 'co2_wltp' => $v->co2_wltp, 'year_from' => $v->year_from, 'year_to' => $v->year_to], self::CLASS2);
        $data += Fields::optional(['specifications' => $v->specifications]);
        $data = app(Enrichers::class)->apply('variant', $v, $data, $ctx, array_merge(self::CLASS1, self::CLASS2));

        return Fields::sparse($data, $fields, self::CLASS1);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function csvColumns(array $fields = []): array
    {
        $columns = array_merge(self::CLASS1_CSV_COLUMNS, self::CLASS2_CSV_COLUMNS, self::CLASS3_COLUMNS);

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
