<?php

declare(strict_types=1);

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VehicleData\Core\Http\Query\CatalogueLookup;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\SlugAlias;

function lookupRequest(string $path): Request
{
    $request = Request::create($path, 'GET');
    $route = app('router')->getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('resolves by id, then by live slug, then redirects an alias, then 404s', function (): void {
    $make = Make::factory()->create(['slug' => 'volkswagen', 'public_id' => '01J8ZQ3W7S5K4M2N9P6R8T1V0X']);
    SlugAlias::query()->create(['record_type' => $make->getMorphClass(), 'record_id' => $make->id, 'slug' => 'vw']);

    expect(CatalogueLookup::find(Make::query(), '01J8ZQ3W7S5K4M2N9P6R8T1V0X', lookupRequest('/v1/makes/01J8ZQ3W7S5K4M2N9P6R8T1V0X'))->id)->toBe($make->id)
        ->and(CatalogueLookup::find(Make::query(), 'volkswagen', lookupRequest('/v1/makes/volkswagen'))->id)->toBe($make->id);

    try {
        CatalogueLookup::find(Make::query(), 'vw', lookupRequest('/v1/makes/vw?lang=en'));
        $this->fail('expected a redirect');
    } catch (HttpResponseException $e) {
        $res = $e->getResponse();
        expect($res->getStatusCode())->toBe(301)
            ->and($res->headers->get('Location'))->toEndWith('/v1/makes/volkswagen?lang=en')
            ->and($res->headers->get('Cache-Control'))->toContain('max-age=86400')->toContain('private');
    }

    expect(fn () => CatalogueLookup::find(Make::query(), 'nope', lookupRequest('/v1/makes/nope')))->toThrow(NotFoundHttpException::class);
    expect(fn () => CatalogueLookup::find(Make::query(), '01J8ZQ3W7S5K4M2N9P6R8T1V0Y', lookupRequest('/v1/makes/01J8ZQ3W7S5K4M2N9P6R8T1V0Y')))->toThrow(NotFoundHttpException::class);
});

it('falls through to the slug lookup when a key that looks like an id matches no public_id', function (): void {
    // PublicIdFormat::looksLike() only checks shape (26 Crockford-base32 characters) — a slug
    // that happens to have that exact shape is not forbidden. byId() 404ing on such a key must
    // not short-circuit find(): it has to fall through to the slug (and then alias) lookup below,
    // exactly as an ordinary slug would.
    $key = 'TESTTESTTESTTESTTESTTESTTE'; // 26 chars, all valid Crockford base32
    $make = Make::factory()->create(['slug' => $key]);

    expect(CatalogueLookup::find(Make::query(), $key, lookupRequest('/v1/makes/'.$key))->id)->toBe($make->id);
});
