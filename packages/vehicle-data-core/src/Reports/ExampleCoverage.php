<?php

declare(strict_types=1);

namespace VehicleData\Core\Reports;

use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Taxonomies\TaxonomyDefinitions;

/**
 * For every taxonomy term backed by a fixture (fuel/eu_category/euro_norm codes on
 * `vd_variants`, and the `autoturism` national category via `ro_fleet_count`), up to two
 * real examples of DISTINCT makes — never two variants of the same make — so
 * `docs/data-sources.md` demonstrates genuine catalogue diversity rather than one make
 * repeated. Terms the fixtures don't reach (e.g. `hydrogen`, most `body_type`/`gearbox`
 * terms which have no source in v1 at all) simply get an empty list.
 */
final class ExampleCoverage
{
    /** @return array<string, array<string, list<array{name: string, ref: string}>>> */
    public static function compute(): array
    {
        $out = [];
        foreach (TaxonomyDefinitions::all() as $taxonomy => $def) {
            foreach ($def['terms'] as $code) {
                $out[$taxonomy][$code] = match ($taxonomy) {
                    'fuel' => self::variants('fuel_code', $code),
                    'eu_category' => self::variants('eu_category_code', $code),
                    'euro_norm' => self::variants('euro_norm_code', $code),
                    'national_category' => $code === 'autoturism' ? self::fleetMakes() : [],
                    default => [],
                };
            }
        }

        return $out;
    }

    /**
     * Two examples of distinct makes for a variant taxonomy column, in a stable order
     * (by id) so the same fixtures always produce the same documented examples.
     *
     * @return list<array{name: string, ref: string}>
     */
    private static function variants(string $column, string $code): array
    {
        $seen = [];
        $examples = [];
        foreach (Variant::query()->with('model.make')->where($column, $code)->orderBy('id')->lazy() as $v) {
            // vd_variants.model_id and vd_models.make_id are required, cascading foreign
            // keys: the relations are never actually null at runtime, but BelongsTo::__get()
            // is typed nullable — narrowed back here for PHPStan/Larastan (see the same note
            // on VehicleModelResource::make()).
            /** @var VehicleModel $model */
            $model = $v->model;
            /** @var Make $make */
            $make = $model->make;
            if (isset($seen[$make->slug])) {
                continue;
            }
            $seen[$make->slug] = true;
            $examples[] = [
                // Documentation wording only: skip the make prefix when the model name already starts with it ("Fiat 500", not "Fiat Fiat 500").
                'name' => (str_starts_with(mb_strtolower($model->name), mb_strtolower($make->name).' ') ? $model->name : $make->name.' '.$model->name).($v->engine_cc ? ' '.$v->engine_cc.' cm³' : '').($v->power_kw ? ' '.$v->power_kw.' kW' : ''),
                'ref' => 'variant:'.$v->public_id,
            ];
            if (count($examples) === 2) {
                break;
            }
        }

        return $examples;
    }

    /** @return list<array{name: string, ref: string}> */
    private static function fleetMakes(): array
    {
        return array_values(Make::query()->whereNotNull('ro_fleet_count')->orderByDesc('ro_fleet_count')->limit(2)->get()
            ->map(fn (Make $m) => ['name' => $m->name.' ('.number_format((float) $m->ro_fleet_count, 0, ',', '.').' vehicles, '.$m->ro_fleet_year.')', 'ref' => 'make:'.$m->slug])
            ->all());
    }
}
