<?php

declare(strict_types=1);

namespace VehicleData\Core\Contracts;

interface SpecificationSchema
{
    /** Registry key, snake_case (car, drone, …). */
    public function kind(): string;

    /**
     * JSON Schema (draft 2020-12) for the `specifications` object of a variant of this kind.
     *
     * @return array<string,mixed>
     */
    public function jsonSchema(): array;

    /**
     * OpenAPI 3.0 schema object inserted as components.schemas.<Kind>Specifications.
     *
     * @return array<string,mixed>
     */
    public function openApiFragment(): array;
}
