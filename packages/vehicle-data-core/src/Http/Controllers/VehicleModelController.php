<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Http\Query\CatalogueLookup;
use VehicleData\Core\Http\Query\Envelope;
use VehicleData\Core\Http\Query\Format;
use VehicleData\Core\Http\Query\ListParams;
use VehicleData\Core\Http\Query\Provenance;
use VehicleData\Core\Http\Resources\EnrichmentContext;
use VehicleData\Core\Http\Resources\VariantResource;
use VehicleData\Core\Http\Resources\VehicleModelResource;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Taxonomies\TaxonomyCodeResolver;

final class VehicleModelController
{
    public function __construct(private readonly LabelResolver $labels) {}

    public function show(Request $request, string $key): JsonResponse
    {
        Format::negotiate($request, false);
        $lang = $this->labels->resolve($request);
        $m = CatalogueLookup::find(VehicleModel::query()->with('make'), $key, $request);

        return Envelope::item(VehicleModelResource::make($m, new EnrichmentContext($lang, $request->attributes->get('client'))), Provenance::for($m), $lang);
    }

    public function variants(Request $request, string $key): Response
    {
        $lang = $this->labels->resolve($request);
        $model = CatalogueLookup::find(VehicleModel::query()->with('make'), $key, $request);
        $taxonomyFilters = array_values(array_filter(['fuel', 'eu_category', 'euro_norm'], fn (string $name): bool => $request->query->has($name)));
        $canonicalCodes = TaxonomyCodeResolver::canonicalCodes($taxonomyFilters);
        $codes = fn (string $tax) => implode(',', array_keys($canonicalCodes[$tax] ?? []));
        $p = ListParams::fromRequest($request, ['power_kw', 'engine_cc', 'co2_wltp', 'year_from', 'updated_at'], [
            'fuel' => 'sometimes|in:'.$codes('fuel'), 'eu_category' => 'sometimes|in:'.$codes('eu_category'), 'euro_norm' => 'sometimes|in:'.$codes('euro_norm'),
            'year' => 'sometimes|integer|min:1900|max:2100', 'power_kw_min' => 'sometimes|integer|min:0', 'power_kw_max' => 'sometimes|integer|min:0',
            'engine_cc_min' => 'sometimes|integer|min:0', 'engine_cc_max' => 'sometimes|integer|min:0',
        ], $lang);
        $ctx = new EnrichmentContext($lang, $request->attributes->get('client'));
        $f = $p->filters;
        foreach ($taxonomyFilters as $taxonomy) {
            if (isset($f[$taxonomy])) {
                $f[$taxonomy] = $canonicalCodes[$taxonomy][$f[$taxonomy]];
            }
        }
        $query = Variant::query()->with('model.make')->where('model_id', $model->id)
            ->when($f['fuel'] ?? null, fn ($q, $v) => $q->where('fuel_code', $v))
            ->when($f['eu_category'] ?? null, fn ($q, $v) => $q->where('eu_category_code', $v))
            ->when($f['euro_norm'] ?? null, fn ($q, $v) => $q->where('euro_norm_code', $v))
            ->when($f['year'] ?? null, fn ($q, $y) => $q->where('year_from', '<=', $y)->where(fn ($w) => $w->whereNull('year_to')->orWhere('year_to', '>=', $y)))
            ->when(isset($f['power_kw_min']), fn ($q) => $q->where('power_kw', '>=', $f['power_kw_min']))
            ->when(isset($f['power_kw_max']), fn ($q) => $q->where('power_kw', '<=', $f['power_kw_max']))
            ->when(isset($f['engine_cc_min']), fn ($q) => $q->where('engine_cc', '>=', $f['engine_cc_min']))
            ->when(isset($f['engine_cc_max']), fn ($q) => $q->where('engine_cc', '<=', $f['engine_cc_max']))
            ->when($p->updatedSince, fn ($q, $s) => $q->where('updated_at', '>=', $s))
            ->orderBy($p->sort ?? 'power_kw', $p->sortDesc ? 'desc' : 'asc')->orderBy('id');

        return Envelope::list($query->paginate($p->perPage, ['*'], 'page', $p->page), $p, fn (Variant $v) => VariantResource::make($v, $ctx, $p->fields), $request, VariantResource::csvColumns($p->fields));
    }
}
