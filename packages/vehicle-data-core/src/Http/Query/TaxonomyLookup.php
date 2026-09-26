<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyAlias;
use VehicleData\Core\Support\PublicIdFormat;

/** Resolves a stable taxonomy id, current name, or retired name (ADR 0009). */
final class TaxonomyLookup
{
    public static function find(string $key, Request $request): Taxonomy
    {
        if (PublicIdFormat::looksLike($key)) {
            $taxonomy = Taxonomy::query()->where('public_id', $key)->first();
            if ($taxonomy !== null) {
                return $taxonomy;
            }
        }
        $taxonomy = Taxonomy::query()->where('name', $key)->first();
        if ($taxonomy !== null) {
            return $taxonomy;
        }
        $alias = TaxonomyAlias::query()->where('name', $key)->first();
        if ($alias === null) {
            throw new NotFoundHttpException('Not found.');
        }
        $taxonomy = Taxonomy::query()->find($alias->taxonomy_id);
        if ($taxonomy === null) {
            throw new NotFoundHttpException('Not found.');
        }

        throw new HttpResponseException(self::redirect($request, $taxonomy->name));
    }

    private static function redirect(Request $request, string $name): RedirectResponse
    {
        $route = $request->route();
        if ($route === null || ! $route->hasParameter('key')) {
            throw new NotFoundHttpException('Not found.');
        }
        $url = route($route->getName() ?? throw new NotFoundHttpException('Not found.'), array_merge($route->parameters(), ['key' => $name]));
        $qs = $request->getQueryString();
        $response = (new RedirectResponse($qs === null ? $url : $url.'?'.$qs, 301, ['Cache-Control' => 'max-age=86400, private']))->setContent('');
        $response->headers->remove('Content-Type');

        return $response;
    }
}
