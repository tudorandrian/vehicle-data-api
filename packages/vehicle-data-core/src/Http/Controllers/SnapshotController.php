<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VehicleData\Core\Http\Query\Provenance;
use VehicleData\Core\Http\Resources\EnrichmentContext;
use VehicleData\Core\Http\Resources\MakeResource;
use VehicleData\Core\Http\Resources\ManufacturerResource;
use VehicleData\Core\Http\Resources\VariantResource;
use VehicleData\Core\Http\Resources\VehicleModelResource;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;

/**
 * Whole-resource sync: a gzip ARTIFACT of JSON Lines (one payload per line), streamed with
 * lazyById() cursors so the whole catalogue is never loaded into memory at once
 * (memory stays flat whatever the catalogue size).
 *
 * The body is a downloadable `.ndjson.gz` file, not a transport-encoded
 * representation - `Content-Type: application/gzip`, no `Content-Encoding` header. Sending
 * `Content-Encoding: gzip` on a route with no `Accept-Encoding` negotiation lets a
 * transparently-decoding client or proxy hand the caller decompressed bytes under a
 * `.gz` filename; `application/gzip` avoids that ambiguity entirely.
 *
 * `X-Data-Licences` (the distinct licence ids of every source that has ever
 * contributed a record of this resource type) and a `Link: …; rel="license"` to
 * `docs/data-sources.md` travel with the bulk download itself, since a snapshot consumer
 * may never see the per-record `sources[]` array the single-item endpoints return.
 *
 * `updated_since` is validated with Laravel's `date` rule before use - an
 * invalid value must produce a 422 problem (via ProblemRenderer, from the thrown
 * ValidationException), never an uncaught parse exception / 500.
 */
final class SnapshotController
{
    private const RESOURCES = ['manufacturers', 'makes', 'models', 'variants'];

    /** Resource name => the morph alias (see CoreServiceProvider::enforceMorphMap()) its records are stored under in vd_record_sources. */
    private const MORPH = ['manufacturers' => 'manufacturer', 'makes' => 'make', 'models' => 'model', 'variants' => 'variant'];

    public const LICENSE_DOC_URL = 'https://github.com/tudorandrian/vehicle-data-api/blob/main/docs/data-sources.md';

    public function __construct(private readonly LabelResolver $labels) {}

    public function show(Request $request, string $resource): StreamedResponse
    {
        if (! in_array($resource, self::RESOURCES, true)) {
            throw new NotFoundHttpException('Unknown snapshot resource.');
        }

        $lang = $this->labels->resolve($request);
        $data = Validator::make($request->query(), ['updated_since' => 'sometimes|date'])->validate();
        $since = isset($data['updated_since']) ? CarbonImmutable::parse((string) $data['updated_since'])->utc() : null;
        $ctx = new EnrichmentContext($lang, $request->attributes->get('client'));

        /** @var array{0: \Illuminate\Database\Eloquent\Builder<*>, 1: callable(mixed): array<string,mixed>} $pair */
        $pair = match ($resource) {
            'manufacturers' => [Manufacturer::query(), fn (Manufacturer $m) => ManufacturerResource::make($m, $ctx)],
            'makes' => [Make::query()->with('manufacturer'), fn (Make $m) => MakeResource::make($m, $ctx)],
            'models' => [VehicleModel::query()->with('make'), fn (VehicleModel $m) => VehicleModelResource::make($m, $ctx)],
            'variants' => [Variant::query()->with('model.make'), fn (Variant $v) => VariantResource::make($v, $ctx)],
        };
        [$query, $map] = $pair;
        $query->when($since, fn ($q, $s) => $q->where('updated_at', '>=', $s));

        $licenceIds = Source::query()
            ->whereIn('id', RecordSource::query()->where('record_type', self::MORPH[$resource])->select('source_id')->distinct())
            ->orderBy('licence_id')->pluck('licence_id')->unique()->values()->all();
        $attribution = Provenance::attributionHeaderForType(self::MORPH[$resource]);

        return new StreamedResponse(function () use ($query, $map): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            $gz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            if ($gz === false) {
                fclose($out);

                return;
            }
            foreach ($query->lazyById(1000) as $record) {
                $chunk = deflate_add($gz, json_encode($map($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", ZLIB_NO_FLUSH);
                if ($chunk !== false) {
                    fwrite($out, $chunk);
                }
            }
            $final = deflate_add($gz, '', ZLIB_FINISH);
            if ($final !== false) {
                fwrite($out, $final);
            }
            fclose($out);
        }, 200, [
            // No Content-Encoding: this is a gzip file artifact (application/gzip), not a
            // transport-encoded representation of some other media type - see the class
            // docblock.
            'Content-Type' => 'application/gzip',
            'Content-Language' => $lang,
            'Content-Disposition' => 'attachment; filename="'.$resource.'-'.now()->toDateString().'.ndjson.gz"',
            // private: a keyed download must never be served by a shared cache to a caller
            // without a key. Vary (Authorization, Origin) is set by ClientCors.
            'Cache-Control' => 'private, max-age=0',
            'X-Data-Licences' => implode(',', $licenceIds),
            'Link' => '<'.self::LICENSE_DOC_URL.'>; rel="license"',
        ] + ($attribution === null ? [] : ['X-Data-Attribution' => $attribution]));
    }
}
