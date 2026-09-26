<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use VehicleData\Core\Http\Query\CatalogueLookup;
use VehicleData\Core\Http\Query\Envelope;
use VehicleData\Core\Http\Query\Format;
use VehicleData\Core\Http\Query\Provenance;
use VehicleData\Core\Http\Resources\EnrichmentContext;
use VehicleData\Core\Http\Resources\VariantResource;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Variant;

final class VariantController
{
    public function __construct(private readonly LabelResolver $labels) {}

    public function show(Request $request, string $id): JsonResponse
    {
        Format::negotiate($request, false);
        $lang = $this->labels->resolve($request);
        $v = CatalogueLookup::byId(Variant::query()->with('model.make'), $id);

        return Envelope::item(VariantResource::make($v, new EnrichmentContext($lang, $request->attributes->get('client'))), Provenance::for($v), $lang);
    }
}
