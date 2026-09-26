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
use VehicleData\Core\Http\Resources\ManufacturerResource;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Manufacturer;

final class ManufacturerController
{
    public function __construct(private readonly LabelResolver $labels) {}

    public function index(Request $request): Response
    {
        $lang = $this->labels->resolve($request);
        $p = ListParams::fromRequest($request, ['name', 'founded_year', 'country_code', 'updated_at'], ['country_code' => 'sometimes|string|size:2'], $lang);
        $ctx = new EnrichmentContext($lang, $request->attributes->get('client'));

        $query = Manufacturer::query()
            ->when($p->q, fn ($q, $term) => $q->where('name', 'like', addcslashes($term, '%_').'%'))
            ->when($p->filters['country_code'] ?? null, fn ($q, $cc) => $q->where('country_code', strtoupper($cc)))
            ->when($p->updatedSince, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderBy($p->sort ?? 'name', $p->sortDesc ? 'desc' : 'asc')->orderBy('id');

        return Envelope::list($query->paginate($p->perPage, ['*'], 'page', $p->page), $p, fn (Manufacturer $m) => ManufacturerResource::make($m, $ctx, $p->fields), $request, ManufacturerResource::csvColumns($p->fields));
    }

    public function show(Request $request, string $key): JsonResponse
    {
        Format::negotiate($request, false);
        $lang = $this->labels->resolve($request);
        $m = CatalogueLookup::find(Manufacturer::query(), $key, $request);

        return Envelope::item(ManufacturerResource::make($m, new EnrichmentContext($lang, $request->attributes->get('client'))), Provenance::for($m), $lang);
    }
}
