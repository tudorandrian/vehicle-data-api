<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Http\Problem\Problem;

final class LimitBodySize
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('core.max_body_bytes', 65536);
        if ((int) $request->headers->get('Content-Length', '0') > $max) {
            return Problem::response(413, 'Payload Too Large', "Request bodies larger than {$max} bytes are not accepted.", type: '/problems/payload-too-large');
        }

        return $next($request);
    }
}
