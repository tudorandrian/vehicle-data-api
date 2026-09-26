<?php

declare(strict_types=1);

namespace VehicleData\Core\Contracts;

use Illuminate\Database\Eloquent\Model;
use VehicleData\Core\Http\Resources\EnrichmentContext;

interface Enricher
{
    /** Resource names: manufacturer, make, model, variant. */
    public function supports(string $resource): bool;

    /**
     * Runs before serialisation. May only add class-3 (optional) keys; core keys are protected.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function enrich(Model $record, array $payload, EnrichmentContext $ctx): array;
}
