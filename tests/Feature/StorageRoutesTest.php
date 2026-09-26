<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('never registers the local-disk storage serving routes, especially not the anonymous PUT upload route', function (): void {
    $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

    expect($uris)->not->toContain('storage/{path}');
    foreach (Route::getRoutes() as $route) {
        if ($route->uri() === 'storage/{path}') {
            expect($route->methods())->not->toContain('PUT');
        }
    }
});
