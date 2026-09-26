<?php

declare(strict_types=1);

namespace VehicleData\Core\Examples;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;
use VehicleData\Core\Auth\ApiKey;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Kinds\OpenApiAssembler;
use VehicleData\Core\Models\ApiClient;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\PackagePaths;

/**
 * Renders the documented example requests against the seeded database through the real HTTP
 * kernel, with the volatile parts (timestamps, request ids, rate-limit counters, dates)
 * replaced by fixed values, so the output is byte-stable across seeds and can be committed
 * and diffed in CI. Ids are stable because the seed mints them deterministically (ADR 0007
 * addendum).
 *
 * A render leaves nothing behind: it runs inside a database transaction that is always rolled
 * back, which removes the throwaway API clients and everything the requests wrote because of
 * them (scopes, `vd_api_requests` rows from the terminate phase, rate-limit counters and, on
 * the database cache store, the cached key lookups).
 *
 * @phpstan-type ExampleRequest array{name: string, operation: string, path: string, query?: array<string, string>, headers?: array<string, string>, scopes: list<string>|null, expect: int, body?: bool, rate_per_minute?: int, prime?: int}
 * @phpstan-type Example array{name: string, operation: string, method: string, path: string, query: array<string, string>, headers: array<string, string>, scopes: list<string>, status: int, response_headers: array<string, string>, body: mixed}
 */
final class ExampleRenderer
{
    public const BASE_URL = 'http://localhost:8087';

    private const FIXED_TIME = '2026-01-01T00:00:00+00:00';

    private const FIXED_DATE = '2026-01-01';

    /** The readiness check reports import times as the database returns them (`Y-m-d H:i:s`). */
    private const FIXED_DB_TIME = '2026-01-01 00:00:00';

    private const FIXED_REQUEST_ID = '00000000-0000-4000-8000-000000000000';

    /** Shaped like a real tag, so it still matches the contract's `^W/"[0-9a-f]{64}"$`. */
    private const FIXED_ETAG = 'W/"0000000000000000000000000000000000000000000000000000000000000000"';

    /** `Retry-After` on a 429 is seconds-until-the-minute-ends, so it is real-clock-dependent; pinned like every other volatile value. */
    private const FIXED_RETRY_AFTER = '56';

    /** The health `data.version` default (`APP_VERSION=dev`), pinned so a release build neither fails `--check` nor commits its version. */
    private const FIXED_VERSION = 'dev';

    /**
     * The documented default rate. One render sends 18 requests on the `catalogue:read` key,
     * well under it; a render that crossed it would get a 429 and fail on the expected status.
     */
    private const RATE_PER_MINUTE = 60;

    /** RFC 5737 documentation address, so the 401 example's per-IP failure counter never lands on a real caller's. */
    private const CLIENT_IP = '192.0.2.1';

    /** Response headers worth showing; everything else (Date, Set-Cookie, …) is dropped. */
    private const KEEP_HEADERS = ['content-type', 'content-language', 'cache-control', 'vary', 'etag', 'x-request-id', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-data-attribution', 'x-data-licences', 'content-disposition', 'link', 'retry-after', 'www-authenticate', 'access-control-allow-origin', 'location'];

    /** @var array<string, array{0: ApiClient, 1: string}> scopes (comma-joined) => [client, plain key] */
    private array $keys = [];

    private readonly string $fragmentPath;

    private readonly string $dir;

    /**
     * @param  string|null  $fragmentPath  where the OpenAPI fragment is compared and written; the committed file (PackagePaths::examplesFragment()) by default
     * @param  string|null  $dir  where examples are rendered, diffed and saved; the committed directory (PackagePaths::examples()) by default
     */
    public function __construct(private readonly Kernel $kernel, ?string $fragmentPath = null, ?string $dir = null)
    {
        $this->fragmentPath = $fragmentPath ?? PackagePaths::examplesFragment();
        $this->dir = $dir ?? PackagePaths::examples();
    }

    /** Where the OpenAPI fragment is compared and written (the injected path, or the committed file). */
    public function fragmentPath(): string
    {
        return $this->fragmentPath;
    }

    /** Where examples are rendered, diffed and saved (the injected directory, or the committed resources directory). */
    public function dir(): string
    {
        return $this->dir;
    }

    /** @return array<string, Example> keyed by name */
    public function render(): array
    {
        $out = [];
        DB::beginTransaction();
        try {
            foreach ($this->requests() as $req) {
                $out[$req['name']] = $this->renderOne($req);
            }
        } finally {
            foreach ($this->keys as [, $plain]) {
                Cache::forget('client:'.ApiKey::hash($plain));
            }
            $this->keys = [];
            DB::rollBack();
        }

        return $out;
    }

    /**
     * Resolves placeholders and the key; returns [path with query, request headers].
     *
     * @param  ExampleRequest  $req
     * @return array{0: string, 1: array<string, string>}
     */
    private function prepare(array $req): array
    {
        $path = $this->resolvePath($req['path']);
        $query = $req['query'] ?? [];
        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }
        $headers = ['Accept' => 'application/json'] + ($req['headers'] ?? []);
        if ($req['scopes'] !== null) {
            $headers['Authorization'] = 'Bearer '.$this->key($req['scopes'], $req['rate_per_minute'] ?? null);
        }

        return [$path, $headers];
    }

    /**
     * The request an example sends, as the renderer sends it. Public so tests replay exactly
     * the same request (the test client would add Symfony's default Accept-Language too).
     *
     * For tests only, where RefreshDatabase resets the schema between tests: unlike render(),
     * this mints its API key outside any transaction, so a key minted here outlives the calling
     * test unless the database itself is refreshed afterward. Anywhere else it refuses.
     *
     * @param  ExampleRequest  $req
     */
    public function request(array $req): Request
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('ExampleRenderer::request() is for tests only: it mints an API key outside any transaction.');
        }

        return $this->build($req)[0];
    }

    /**
     * @param  ExampleRequest  $req
     * @return array{0: Request, 1: string, 2: array<string, string>} the request, its path with query, its headers
     */
    private function build(array $req): array
    {
        [$uri, $headers] = $this->prepare($req);
        $request = Request::create(self::BASE_URL.$uri, 'GET', [], [], [], $this->serverVars($headers));
        if (! array_key_exists('accept-language', array_change_key_case($headers))) {
            // Request::create() sends `Accept-Language: en-us,en;q=0.5` by default, which would
            // document English labels as the default; the API's default (no header) is `ro`. An
            // example that needs a language states it in requests.php. The header bag is built
            // from the server bag inside create(), so both are cleared.
            $request->headers->remove('Accept-Language');
            $request->server->remove('HTTP_ACCEPT_LANGUAGE');
        }

        return [$request, $uri, $headers];
    }

    public function write(string $dir): void
    {
        $this->save($dir, $this->render());
    }

    /**
     * Writes already rendered examples (so a caller that also needs the render does not pay for
     * a second one) and removes files of examples that no longer exist.
     *
     * @param  array<string, Example>  $examples
     */
    public function save(string $dir, array $examples): void
    {
        foreach ($examples as $name => $example) {
            file_put_contents("$dir/$name.json", self::encode($example));
        }
        foreach ($this->stale($dir, $examples) as $name) {
            unlink("$dir/$name.json");
        }
    }

    /**
     * @param  array<string, Example>|null  $examples  an existing render, so a caller that also needs it renders once
     * @return list<string> names whose committed file is missing, differs, or has no request any more
     */
    public function diff(string $dir, ?array $examples = null): array
    {
        $examples ??= $this->render();
        $differences = [];
        foreach ($examples as $name => $example) {
            $file = "$dir/$name.json";
            if (! is_file($file) || file_get_contents($file) !== self::encode($example)) {
                $differences[] = $name;
            }
        }

        return [...$differences, ...$this->stale($dir, $examples)];
    }

    /**
     * The fragment built from a fresh render.
     *
     * @return array<string, mixed>
     */
    public function openApiFragment(): array
    {
        return $this->fragment($this->render());
    }

    /**
     * The OpenAPI fragment for already rendered examples: each JSON body becomes the `example` of
     * the response it documents (the first example per operation and status wins), and parts of
     * the bodies become the `example` of their component schema.
     *
     * A response the contract declares as a `$ref` gets its example on the referenced component
     * response, never beside the `$ref`; the media type is the declared one that matches the
     * response's Content-Type. A response, media type or schema the contract does not have is an
     * error, never created. Bodies that are not JSON (CSV, gzip, YAML) are not attached.
     *
     * @param  array<string, Example>  $examples
     * @return array<string, mixed>
     */
    public function fragment(array $examples): array
    {
        /** @var array{paths: array<string, mixed>, components: array{responses: array<string, mixed>, schemas: array<string, mixed>}} $contract */
        $contract = Yaml::parseFile(PackagePaths::openApi());
        $fragment = [];
        foreach ($examples as $ex) {
            if (! is_array($ex['body'])) {
                continue;
            }
            [$target, $response] = $this->responseTarget($contract, $ex);
            $media = strtolower(trim(explode(';', $ex['response_headers']['content-type'] ?? '')[0]));
            if (! isset($response['content']) || ! is_array($response['content']) || ! isset($response['content'][$media])) {
                throw new RuntimeException("{$ex['name']}: the contract declares no $media content for {$ex['operation']} {$ex['status']}");
            }
            $slot = &$fragment;
            foreach ([...$target, 'content', $media] as $key) {
                $slot = &$slot[$key];
            }
            $slot['example'] ??= $ex['body'];
            unset($slot);
        }

        foreach ($this->schemaExamples($examples) as $name => $example) {
            if (! isset($contract['components']['schemas'][$name])) {
                throw new RuntimeException("The contract has no schema $name");
            }
            if ($example === null) {
                throw new RuntimeException("No rendered example for schema $name");
            }
            $fragment['components']['schemas'][$name] = ['example' => $example];
        }

        /** @var array<string, mixed> $sorted the top-level keys stay `components` and `paths` */
        $sorted = self::sortStructure($fragment);

        return $sorted;
    }

    /**
     * Whether the fragment file is byte-identical to this fragment.
     *
     * @param  array<string, mixed>  $fragment
     */
    public function fragmentIsCurrent(array $fragment): bool
    {
        return is_file($this->fragmentPath) && file_get_contents($this->fragmentPath) === self::dumpFragment($fragment);
    }

    /** @param array<string, mixed> $fragment */
    public function writeFragment(array $fragment): void
    {
        file_put_contents($this->fragmentPath, self::dumpFragment($fragment));
    }

    /** @param array<string, mixed> $fragment */
    public static function dumpFragment(array $fragment): string
    {
        return "# Generated by `php artisan vehicle:examples render --openapi` from the rendered examples; do not edit.\n"
            .OpenApiAssembler::dump($fragment, 20);
    }

    /**
     * @param  array{paths: array<string, mixed>, components: array{responses: array<string, mixed>, schemas: array<string, mixed>}}  $contract
     * @param  Example  $ex
     * @return array{0: list<string|int>, 1: array<string, mixed>} the fragment path of the documented response, and that response
     */
    private function responseTarget(array $contract, array $ex): array
    {
        $response = $contract['paths'][$ex['operation']]['get']['responses'][$ex['status']] ?? null;
        if (! is_array($response)) {
            throw new RuntimeException("{$ex['name']}: the contract has no {$ex['status']} response on {$ex['operation']}");
        }
        if (! isset($response['$ref'])) {
            return [['paths', $ex['operation'], 'get', 'responses', $ex['status']], $response];
        }
        $prefix = '#/components/responses/';
        $ref = (string) $response['$ref'];
        $name = substr($ref, strlen($prefix));
        $shared = $contract['components']['responses'][$name] ?? null;
        if (! str_starts_with($ref, $prefix) || ! is_array($shared)) {
            throw new RuntimeException("{$ex['name']}: cannot resolve $ref");
        }

        return [['components', 'responses', $name], $shared];
    }

    /**
     * One example per component schema, cut from the rendered bodies.
     *
     * @param  array<string, Example>  $examples
     * @return array<string, mixed>
     */
    private function schemaExamples(array $examples): array
    {
        $pick = function (string $name, string|int ...$keys) use ($examples): mixed {
            $node = $examples[$name]['body'] ?? null;
            foreach ($keys as $key) {
                $node = is_array($node) ? ($node[$key] ?? null) : null;
            }

            return $node;
        };

        return [
            'HealthLive' => $pick('health'),
            'HealthReady' => $pick('health-ready'),
            'TaxonomySummary' => $pick('taxonomies', 'data', 0),
            'Taxonomy' => $pick('taxonomy-fuel', 'data'),
            'Term' => $pick('taxonomy-fuel', 'data', 'terms', 0),
            'Manufacturer' => $pick('manufacturer-bmw', 'data'),
            'Logo' => $pick('manufacturer-bmw', 'data', 'logo'),
            'Make' => $pick('make-dacia', 'data'),
            'RoFleet' => $pick('make-dacia', 'data', 'ro_fleet'),
            'Sources' => $pick('make-dacia', 'sources'),
            'ItemMeta' => $pick('make-dacia', 'meta'),
            'Model' => $pick('model-dacia-duster', 'data'),
            'Variant' => $pick('variants-duster-diesel-euro6d', 'data', 0),
            'TermRef' => $pick('variants-duster-diesel-euro6d', 'data', 0, 'fuel'),
            'CarSpecifications' => $pick('variants-duster-diesel-euro6d', 'data', 0, 'specifications'),
            'ListMeta' => $pick('makes', 'meta'),
            'Links' => $pick('makes', 'links'),
            'VinDecode' => $pick('vin', 'data'),
            'Problem' => $pick('error-404'),
            'ForbiddenProblem' => $pick('error-403-scope'),
            'ValidationProblem' => $pick('error-422'),
        ];
    }

    /**
     * Sorts the fragment's own keys (components, paths, statuses, media types, schema names) so
     * the file is stable; an example body keeps the key order of the response it came from.
     *
     * @param  array<int|string, mixed>  $node
     * @return array<int|string, mixed>
     */
    private static function sortStructure(array $node): array
    {
        ksort($node, SORT_STRING);
        foreach ($node as $key => $value) {
            if ($key !== 'example' && is_array($value)) {
                $node[$key] = self::sortStructure($value);
            }
        }

        return $node;
    }

    /** @param Example $example */
    public static function encode(array $example): string
    {
        $out = $example;
        if ($out['query'] === []) {
            $out['query'] = new stdClass; // an empty map is `{}`, never `[]`
        }

        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  ExampleRequest  $req
     * @return Example
     */
    private function renderOne(array $req): array
    {
        $primeCount = $req['prime'] ?? 0;
        // Counters::minuteWindow() buckets by CarbonImmutable::now('UTC'); a real clock tick
        // between the primed request(s) and the captured one below could otherwise land them in
        // two different minute windows, silently undoing the priming and turning the intended
        // 429 into a 200. Freezing the clock for the whole priming+capture sequence (only when
        // priming is actually used) makes that impossible, regardless of wall-clock timing.
        if ($primeCount > 0) {
            CarbonImmutable::setTestNow(CarbonImmutable::now('UTC'));
        }
        try {
            // A "prime" count fires that many identical, discarded requests on the same key
            // first, so the captured request below lands over its rate limit (used with a low
            // `rate_per_minute` override to render a deterministic 429).
            for ($i = 0; $i < $primeCount; $i++) {
                [$primeRequest] = $this->build($req);
                $primeResponse = $this->kernel->handle($primeRequest);
                $this->kernel->terminate($primeRequest, $primeResponse);
            }
            [$request, $uri, $headers] = $this->build($req);
            $response = $this->handle($req, $request);
            $wantBody = ($req['body'] ?? true) !== false;
            $content = (string) $response->getContent();
            if ($wantBody && $response instanceof StreamedResponse) {
                // CSV is streamed to php://output; send it (still inside the transaction, since the
                // callback reads the database lazily) into a buffer, as a web server would send it.
                ob_start();
                try {
                    $response->sendContent();
                } finally {
                    $content = (string) ob_get_clean();
                }
            }
            // The terminate phase writes the usage row (RecordUsage) and the log line; it runs here
            // exactly as it would under a web server, and the transaction in render() drops the row.
            $this->kernel->terminate($request, $response);
            if ($response->getStatusCode() !== $req['expect']) {
                throw new RuntimeException(sprintf('%s: expected %d, got %d', $req['name'], $req['expect'], $response->getStatusCode()));
            }

            return [
                'name' => $req['name'],
                'operation' => $req['operation'],
                'method' => 'GET',
                'path' => explode('?', $uri, 2)[0],
                'query' => $req['query'] ?? [],
                'headers' => array_map(fn (string $v): string => str_starts_with($v, 'Bearer ') ? 'Bearer '.ApiKey::PREFIX.'…' : $v, $headers),
                'scopes' => $req['scopes'] ?? [],
                'status' => $response->getStatusCode(),
                'response_headers' => $this->normaliseHeaders($response->getStatusCode(), $response->headers->all()),
                'body' => $wantBody ? $this->normaliseBody($req['name'], $req['operation'], $content, (string) $response->headers->get('Content-Type')) : null,
            ];
        } finally {
            if ($primeCount > 0) {
                CarbonImmutable::setTestNow(null);
            }
        }
    }

    /**
     * Sends the captured request. `/openapi.yaml` is served by an assembler without the examples
     * fragment for this one request: the fragment is what `render --openapi` is about to
     * regenerate, so a stale one (naming a node the contract no longer has) must not turn this
     * example into a 500 and block its own regeneration. The example records no body, so the
     * rendered file is the same either way.
     *
     * @param  ExampleRequest  $req
     */
    private function handle(array $req, Request $request): Response
    {
        if ($req['operation'] !== '/openapi.yaml') {
            return $this->kernel->handle($request);
        }
        $previous = app(OpenApiAssembler::class);
        app()->instance(OpenApiAssembler::class, new OpenApiAssembler(app(KindRegistry::class), PackagePaths::openApi()));
        try {
            return $this->kernel->handle($request);
        } finally {
            app()->instance(OpenApiAssembler::class, $previous);
        }
    }

    /** @return list<ExampleRequest> */
    private function requests(): array
    {
        /** @var list<ExampleRequest> $requests */
        $requests = require PackagePaths::examples().'/requests.php';

        return $requests;
    }

    /**
     * @param  array<string, Example>  $examples
     * @return list<string>
     */
    private function stale(string $dir, array $examples): array
    {
        $stale = [];
        foreach (glob("$dir/*.json") ?: [] as $file) {
            $name = basename($file, '.json');
            if (! isset($examples[$name])) {
                $stale[] = $name;
            }
        }

        return $stale;
    }

    /** `{id:make:dacia}`, `{slug:model:eu_category=n1}`, `{id:variant:fuel=diesel,euro_norm=euro_6d,make=dacia}` → real values from the seed. */
    private function resolvePath(string $path): string
    {
        return (string) preg_replace_callback('/\{(id|slug):(make|model|variant):([^}]+)\}/', function (array $m): string {
            [, $field, $type, $spec] = $m;
            $column = $field === 'id' ? 'public_id' : 'slug';
            $value = match ($type) {
                'make' => Make::query()->where('slug', $spec)->value($column),
                'model' => VehicleModel::query()->whereHas('variants', fn (Builder $q) => $this->filters($q, $spec))->orderBy('slug')->value($column),
                'variant' => $this->filters(Variant::query(), $spec)->orderBy('public_id')->value($column),
            };
            if (! is_string($value) || $value === '') {
                throw new RuntimeException("No seeded $type for [$spec]");
            }

            return $value;
        }, $path);
    }

    /**
     * Applies `key=value` pairs to a variant query: `make` matches the make slug, anything else
     * the `<key>_code` taxonomy column.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function filters(Builder $query, string $spec): Builder
    {
        foreach (explode(',', $spec) as $pair) {
            [$k, $v] = explode('=', $pair, 2) + [1 => ''];
            $query = match ($k) {
                'make' => $query->whereHas('model.make', fn (Builder $q) => $q->where('slug', $v)),
                default => $query->where($k.'_code', $v),
            };
        }

        return $query;
    }

    /**
     * @param  list<string>  $scopes
     * @param  int|null  $ratePerMinute  a per-minute override for this key only (e.g. to render a 429);
     *                                   kept in a separate cache slot so it never shares a client with the
     *                                   default-rate key of the same scopes
     */
    private function key(array $scopes, ?int $ratePerMinute = null): string
    {
        $k = implode(',', $scopes).($ratePerMinute !== null ? '|rate='.$ratePerMinute : '');
        if (! isset($this->keys[$k])) {
            $plain = ApiKey::generate();
            $client = ApiClient::query()->create(['name' => 'examples', 'owner' => 'examples', 'key_prefix' => ApiKey::prefix($plain), 'key_hash' => ApiKey::hash($plain), 'allowed_origins' => [], 'rate_per_minute' => $ratePerMinute ?? self::RATE_PER_MINUTE, 'daily_quota' => 1000000]);
            foreach ($scopes as $scope) {
                $client->scopes()->create(['scope' => $scope]);
            }
            $this->keys[$k] = [$client, $plain];
        }

        return $this->keys[$k][1];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverVars(array $headers): array
    {
        $server = ['REMOTE_ADDR' => self::CLIENT_IP];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function normaliseBody(string $name, string $operation, string $content, string $contentType): mixed
    {
        if (! str_contains($contentType, 'json')) {
            return $content; // CSV: shown verbatim
        }
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        // Decoding into arrays turns an empty object `{}` into `[]`: refuse to commit a body whose
        // shape the round trip would change, rather than document a wrong type silently.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if (json_encode($decoded, $flags) !== json_encode(json_decode($content, false, 512, JSON_THROW_ON_ERROR), $flags)) {
            throw new RuntimeException("$name: the response body has an empty JSON object, which the example renderer would write as []");
        }
        if (! is_array($decoded)) {
            return $decoded;
        }
        // By key name only where the name is unambiguous across every response: the envelope's
        // `meta.generated_at`, the `sources[].retrieved_at` import time, and a problem's `request_id`.
        array_walk_recursive($decoded, function (mixed &$v, int|string $k): void {
            if ($k === 'generated_at' || $k === 'retrieved_at') {
                $v = self::FIXED_TIME;
            }
            if ($k === 'request_id') {
                $v = self::FIXED_REQUEST_ID;
            }
        });

        // By position, and only on the health operations: `data.time` (the clock), `data.version`
        // (APP_VERSION), and the readiness check's `data.checks.queue_backlog` (the environment's
        // jobs table) and `data.checks.imports.<source>` (the last successful import of each source).
        if (str_starts_with($operation, '/v1/health') && isset($decoded['data']) && is_array($decoded['data'])) {
            $data = $decoded['data'];
            if (array_key_exists('time', $data)) {
                $data['time'] = self::FIXED_TIME;
            }
            if (array_key_exists('version', $data)) {
                $data['version'] = self::FIXED_VERSION;
            }
            if (isset($data['checks']) && is_array($data['checks'])) {
                if (array_key_exists('queue_backlog', $data['checks'])) {
                    $data['checks']['queue_backlog'] = 0;
                }
                if (isset($data['checks']['imports']) && is_array($data['checks']['imports'])) {
                    $data['checks']['imports'] = array_map(fn (mixed $v): mixed => $v === null ? null : self::FIXED_DB_TIME, $data['checks']['imports']);
                }
            }
            $decoded['data'] = $data;
        }

        return $decoded;
    }

    /**
     * @param  array<string, list<string|null>>  $all
     * @return array<string, string>
     */
    private function normaliseHeaders(int $status, array $all): array
    {
        $out = [];
        foreach ($all as $name => $values) {
            if (! in_array($name, self::KEEP_HEADERS, true)) {
                continue;
            }
            $value = implode(', ', array_filter($values, is_string(...)));
            $out[$name] = match ($name) {
                'x-request-id' => self::FIXED_REQUEST_ID,
                // On a 429, X-RateLimit-Remaining is genuinely and deterministically "0" (ThrottleClient
                // sets it explicitly); everywhere else it is pinned to a fixed, plausible value instead of
                // the real (request-order-dependent) count.
                'x-ratelimit-remaining' => $status === 429 ? $value : (string) (self::RATE_PER_MINUTE - 1),
                // Real-clock-dependent (seconds until the current minute ends): always pinned.
                'retry-after' => self::FIXED_RETRY_AFTER,
                'etag' => self::FIXED_ETAG, // the fingerprint covers sources[].retrieved_at, which differs per seed
                'content-disposition' => (string) preg_replace('/\d{4}-\d{2}-\d{2}/', self::FIXED_DATE, $value),
                default => $value,
            };
        }
        ksort($out);

        return $out;
    }
}
