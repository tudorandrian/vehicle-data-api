<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Support\PublicIdFormat;

/**
 * Resolves the `{key}` of a keyed catalogue route (ADR 0007):
 *  1. a 26-character id → the record by public_id, else 404;
 *  2. a live slug → the record;
 *  3. a retired slug (vd_slug_aliases) → 301 to the same route with the current slug, query string kept;
 *  4. otherwise 404.
 * Variants have no slug: use byId().
 */
final class CatalogueLookup
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    public static function find(Builder $query, string $key, Request $request): Model
    {
        // A slug that happens to look like a 26-character Crockford id (unlikely, but not
        // forbidden by the slug format) must still resolve: try the id lookup first, but fall
        // through to the slug/alias lookup below instead of 404ing outright when it misses.
        if (PublicIdFormat::looksLike($key)) {
            $byId = (clone $query)->where('public_id', $key)->first();
            if ($byId !== null) {
                return $byId;
            }
        }
        $record = (clone $query)->where('slug', $key)->first();
        if ($record !== null) {
            return $record;
        }
        $type = $query->getModel()->getMorphClass();
        $alias = SlugAlias::query()->where('record_type', $type)->where('slug', $key)->first();
        if ($alias === null) {
            throw new NotFoundHttpException('Not found.');
        }
        $canonical = (clone $query)->whereKey($alias->record_id)->first();
        if ($canonical === null) {
            throw new NotFoundHttpException('Not found.');
        }

        throw new HttpResponseException(self::redirect($request, (string) $canonical->getAttribute('slug')));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    public static function byId(Builder $query, string $id): Model
    {
        return (clone $query)->where('public_id', $id)->first() ?? throw new NotFoundHttpException('Not found.');
    }

    private static function redirect(Request $request, string $slug): RedirectResponse
    {
        $route = $request->route();
        $name = $route?->getName() ?? throw new NotFoundHttpException('Not found.');
        if (! $route->hasParameter('key')) {
            throw new NotFoundHttpException('Not found.');
        }
        $params = array_merge($route->parameters(), ['key' => $slug]);
        $url = route($name, $params);
        $qs = $request->getQueryString();

        // The 301 carries no body: the contract documents no `content` for it (only
        // `headers`), and Symfony's RedirectResponse otherwise fills the body with an
        // HTML meta-refresh page whose default Content-Type: text/html would then be a
        // lie about a response with no content - so both go together.
        $response = (new RedirectResponse($qs === null ? $url : $url.'?'.$qs, 301, ['Cache-Control' => 'max-age=86400, private']))->setContent('');
        $response->headers->remove('Content-Type');

        return $response;
    }
}
