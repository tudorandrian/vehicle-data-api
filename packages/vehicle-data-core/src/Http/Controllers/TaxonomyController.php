<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use VehicleData\Core\Http\Query\TaxonomyLookup;
use VehicleData\Core\Http\Resources\TermResource;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Taxonomy;

final class TaxonomyController
{
    /** Keyed responses are never storable by shared caches, which would serve them without a key. */
    public const CACHE = 'private, max-age=0, must-revalidate';

    public function __construct(private readonly LabelResolver $labels) {}

    public function index(Request $request): JsonResponse
    {
        $lang = $this->labels->resolve($request);
        $data = Taxonomy::query()->withCount('terms')->orderBy('name')->get()->map(fn (Taxonomy $t) => [
            'id' => $t->public_id, 'name' => $t->name, 'label' => trans("core::taxonomies._names.{$t->name}", [], $lang), 'description' => $t->description, 'term_count' => $t->terms_count,
        ])->all();

        return (new JsonResponse(['data' => $data, 'meta' => ['generated_at' => now()->toIso8601String(), 'lang' => $lang]], 200, ['Content-Language' => $lang, 'Cache-Control' => self::CACHE]))
            ->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $lang = $this->labels->resolve($request);
        $taxonomy = TaxonomyLookup::find($key, $request)->load(['terms.labels']);
        $data = ['id' => $taxonomy->public_id, 'name' => $taxonomy->name, 'label' => trans("core::taxonomies._names.{$taxonomy->name}", [], $lang), 'description' => $taxonomy->description,
            'terms' => $taxonomy->terms->sortBy('sort_order')->values()->map(fn ($t) => TermResource::make($t, $lang))->all()];

        return (new JsonResponse(['data' => $data, 'meta' => ['generated_at' => now()->toIso8601String(), 'lang' => $lang]], 200, ['Content-Language' => $lang, 'Cache-Control' => self::CACHE]))
            ->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
