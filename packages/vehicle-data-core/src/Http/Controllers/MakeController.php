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
use VehicleData\Core\Http\Resources\MakeResource;
use VehicleData\Core\Http\Resources\VehicleModelResource;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\VehicleModel;

final class MakeController
{
    public function __construct(private readonly LabelResolver $labels, private readonly KindRegistry $kinds) {}

    public function index(Request $request): Response
    {
        $lang = $this->labels->resolve($request);
        $p = ListParams::fromRequest($request, ['name', 'ro_fleet_count', 'updated_at'], ['kind' => 'sometimes|string|in:'.implode(',', $this->kinds->kinds()), 'manufacturer' => 'sometimes|string|alpha_dash|max:120'], $lang);
        $ctx = new EnrichmentContext($lang, $request->attributes->get('client'));
        $query = Make::query()->with('manufacturer')
            ->where('kind', $p->filters['kind'] ?? 'car')
            ->when($p->q, fn ($q, $t) => $q->where('name', 'like', addcslashes($t, '%_').'%'))
            ->when($p->filters['manufacturer'] ?? null, fn ($q, $slug) => $q->whereHas('manufacturer', fn ($m) => $m->where('slug', $slug)))
            ->when($p->updatedSince, fn ($q, $s) => $q->where('updated_at', '>=', $s))
            ->orderBy($p->sort ?? 'name', $p->sortDesc ? 'desc' : 'asc')->orderBy('id');

        return Envelope::list($query->paginate($p->perPage, ['*'], 'page', $p->page), $p, fn (Make $m) => MakeResource::make($m, $ctx, $p->fields), $request, MakeResource::csvColumns($p->fields));
    }

    public function show(Request $request, string $key): JsonResponse
    {
        Format::negotiate($request, false);
        $lang = $this->labels->resolve($request);
        $m = CatalogueLookup::find(Make::query()->with('manufacturer'), $key, $request);

        return Envelope::item(MakeResource::make($m, new EnrichmentContext($lang, $request->attributes->get('client'))), Provenance::for($m), $lang);
    }

    public function models(Request $request, string $key): Response
    {
        $lang = $this->labels->resolve($request);
        $make = CatalogueLookup::find(Make::query(), $key, $request);
        $p = ListParams::fromRequest($request, ['name', 'first_year', 'ro_fleet_count', 'updated_at'], [], $lang);
        $ctx = new EnrichmentContext($lang, $request->attributes->get('client'));
        $query = VehicleModel::query()->with('make')->where('make_id', $make->id)
            ->when($p->q, fn ($q, $t) => $q->where('name', 'like', addcslashes($t, '%_').'%'))
            ->when($p->updatedSince, fn ($q, $s) => $q->where('updated_at', '>=', $s))
            ->orderBy($p->sort ?? 'name', $p->sortDesc ? 'desc' : 'asc')->orderBy('id');

        return Envelope::list($query->paginate($p->perPage, ['*'], 'page', $p->page), $p, fn (VehicleModel $m) => VehicleModelResource::make($m, $ctx, $p->fields), $request, VehicleModelResource::csvColumns($p->fields));
    }
}
