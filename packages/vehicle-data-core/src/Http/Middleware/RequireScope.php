<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Http\Problem\Problem;

final class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = $request->attributes->get('client');
        if (! $client instanceof ResolvedClient || ! $client->hasScope($scope)) {
            return Problem::response(403, 'Forbidden', "This key lacks the '{$scope}' scope.", ['required_scope' => $scope], '/problems/insufficient-scope');
        }

        return $next($request);
    }
}
