<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Illuminate\Http\Response;
use VehicleData\Core\Kinds\OpenApiAssembler;

final class OpenApiController
{
    public function __invoke(OpenApiAssembler $assembler): Response
    {
        return new Response($assembler->yaml(url('/')), 200, ['Content-Type' => 'application/yaml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
