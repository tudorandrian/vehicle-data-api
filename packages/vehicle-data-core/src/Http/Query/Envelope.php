<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Envelope
{
    public const PRIVATE_CACHE = 'private, max-age=0, must-revalidate';

    /**
     * @template TModel of Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  callable(TModel): array<string,mixed>  $map
     * @param  list<string>|null  $csvHeader  A stable, declared CSV column list (see
     *                                        CsvResponder::stream()). Passed through untouched
     *                                        when the negotiated format isn't CSV.
     */
    public static function list(LengthAwarePaginator $paginator, ListParams $params, callable $map, Request $request, ?array $csvHeader = null, string $cacheControl = self::PRIVATE_CACHE): Response
    {
        // Negotiate the representation before mapping/enriching a single item, so
        // a 406 (unsupported Accept/format) never pays for resource work it can't use.
        $format = Format::negotiate($request, true);
        $records = array_values($paginator->items());
        $attribution = Provenance::attributionHeader($records);
        $items = array_map($map, $records);
        if ($format === 'csv') {
            $name = str_replace('/', '-', trim($request->path(), '/')).'-'.now()->toDateString().'.csv';

            // StreamedResponse has no withHeaders(); set headers directly.
            $response = CsvResponder::stream($items, $name, $csvHeader);
            $response->headers->set('Content-Language', $params->lang);
            if ($attribution !== null) {
                $response->headers->set('X-Data-Attribution', $attribution);
            }

            return $response;
        }
        $url = fn (?int $page): ?string => $page === null ? null : $request->fullUrlWithQuery(['page' => $page]);

        $response = new JsonResponse([
            'data' => $items,
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'generated_at' => now()->toIso8601String(), 'lang' => $params->lang],
            'links' => ['self' => $url($paginator->currentPage()), 'next' => $paginator->hasMorePages() ? $url($paginator->currentPage() + 1) : null, 'prev' => $paginator->currentPage() > 1 ? $url($paginator->currentPage() - 1) : null],
        ], 200, ['Content-Language' => $params->lang, 'Cache-Control' => $cacheControl]);
        if ($attribution !== null) {
            $response->headers->set('X-Data-Attribution', $attribution);
        }

        // Readable Romanian labels in the body, not \u escapes.
        return $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<array{key:string,licence:string,attribution:string,retrieved_at:string}>  $sources
     */
    public static function item(array $data, array $sources, string $lang, string $cacheControl = self::PRIVATE_CACHE): JsonResponse
    {
        $response = new JsonResponse(['data' => $data, 'sources' => $sources, 'meta' => ['generated_at' => now()->toIso8601String(), 'lang' => $lang]], 200, ['Content-Language' => $lang, 'Cache-Control' => $cacheControl]);

        return $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
