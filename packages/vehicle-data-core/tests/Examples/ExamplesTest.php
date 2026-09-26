<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\Yaml\Yaml;
use VehicleData\Core\Database\Seeders\ExampleDataSeeder;
use VehicleData\Core\Examples\ExampleRenderer;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Kinds\OpenApiAssembler;
use VehicleData\Core\Models\ApiClient;
use VehicleData\Core\Support\PackagePaths;

beforeEach(function (): void {
    $this->seed(ExampleDataSeeder::class);
});

it('renders every request and the committed files match (run `vehicle:examples render` after a data or serialisation change)', function (): void {
    $renderer = app(ExampleRenderer::class);
    expect($renderer->diff(PackagePaths::examples()))->toBe([]);
});

it('covers every operation in the contract with at least one 2xx example', function (): void {
    $doc = Yaml::parseFile(PackagePaths::openApi());
    $operations = array_keys($doc['paths']);
    $covered = [];
    foreach (app(ExampleRenderer::class)->render() as $example) {
        if ($example['status'] < 300) {
            $covered[$example['operation']] = true;
        }
    }
    expect(array_values(array_diff($operations, array_keys($covered))))->toBe([]);
});

it('every rendered 2xx JSON example validates against the contract', function (): void {
    Spectator::using('openapi.yaml');
    foreach (require PackagePaths::examples().'/requests.php' as $req) {
        if ($req['expect'] >= 300 || ($req['body'] ?? true) === false) {
            continue;
        }
        // The renderer's own request, not the test client's (which adds Symfony's default
        // Accept-Language), so the validated response is the one the example documents.
        $request = app(ExampleRenderer::class)->request($req);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $committed = json_decode((string) file_get_contents(PackagePaths::examples()."/{$req['name']}.json"), true, 512, JSON_THROW_ON_ERROR);
        expect($response->headers->get('Content-Language'))->toBe($committed['response_headers']['content-language'] ?? null, $req['name']);
        TestResponse::fromBaseResponse($response, $request)->assertValidRequest()->assertValidResponse($req['expect']);
    }
});

it('renders in the API default language unless an example asks for one', function (): void {
    $rendered = app(ExampleRenderer::class)->render();
    $diesel = array_values(array_filter($rendered['taxonomy-fuel']['body']['data']['terms'], fn (array $t): bool => $t['code'] === 'diesel'))[0];
    expect($rendered['make-dacia']['response_headers']['content-language'])->toBe('ro')
        ->and($rendered['make-dacia']['headers'])->not->toHaveKey('Accept-Language')
        ->and($rendered['taxonomy-fuel']['response_headers']['content-language'])->toBe('ro')
        ->and($diesel['label'])->toBe('Motorină')
        ->and($rendered['taxonomy-national-category-en']['response_headers']['content-language'])->toBe('en');
});

it('renders a deterministic 429 problem from a dedicated low-rate key, twice in a row', function (): void {
    // error-429 uses its own rate_per_minute=1 key (primed with one discarded request first),
    // never the shared catalogue:read key every other example uses, and its Retry-After is
    // normalised — so the whole example is byte-identical across independent renders (I4).
    $renderer = app(ExampleRenderer::class);
    $first = $renderer->render()['error-429'];
    $second = $renderer->render()['error-429'];
    expect($first)->toBe($second)
        ->and($first['status'])->toBe(429)
        ->and($first['response_headers']['x-ratelimit-limit'])->toBe('1')
        ->and($first['response_headers']['x-ratelimit-remaining'])->toBe('0')
        ->and($first['response_headers'])->toHaveKey('retry-after')
        ->and($first['body']['detail'])->toBe('Rate limit of 1 requests per minute exceeded.');
});

it('serves the contract with an example on every 2xx JSON response and every object schema', function (): void {
    $doc = Yaml::parse((string) $this->get('/openapi.yaml')->assertOk()->getContent());
    foreach ($doc['paths'] as $path => $item) {
        foreach ($item['get']['responses'] as $status => $response) {
            if (isset($response['$ref'])) {
                // A shared response is documented once, on the component (never beside the $ref).
                $response = $doc['components']['responses'][substr($response['$ref'], strlen('#/components/responses/'))];
            }
            if ((int) $status >= 300 || ! isset($response['content']['application/json'])) {
                continue;
            }
            expect($response['content']['application/json'])->toHaveKey('example', message: "missing example for $path $status");
        }
    }
    $withExample = ['Problem', 'ForbiddenProblem', 'ValidationProblem', 'ListMeta', 'ItemMeta', 'Links', 'Sources', 'Term', 'TermRef', 'TaxonomySummary', 'Taxonomy', 'RoFleet', 'Logo', 'Manufacturer', 'Make', 'Model', 'Variant', 'CarSpecifications', 'HealthLive', 'HealthReady', 'VinDecode'];
    // The anyOf wrapper has no shape of its own; its only branch, CarSpecifications, carries the example.
    $without = ['Specifications'];
    foreach ($withExample as $schema) {
        expect($doc['components']['schemas'][$schema])->toHaveKey('example', message: "missing example on schema $schema");
    }
    foreach ($without as $schema) {
        expect($doc['components']['schemas'][$schema])->not->toHaveKey('example');
    }
    // A schema added to the contract must get an example here, or be listed as an exception.
    $all = array_keys($doc['components']['schemas']);
    sort($all);
    $listed = [...$withExample, ...$without];
    sort($listed);
    expect($all)->toBe($listed);
});

it('attaches each example to the media type the contract declares for its response', function (): void {
    $rendered = app(ExampleRenderer::class)->render();
    $fragment = app(ExampleRenderer::class)->fragment($rendered);
    // Shared problem responses carry the example on the component; the one inline problem on its path.
    expect($fragment['components']['responses']['Unauthorized']['content']['application/problem+json']['example'])->toBe($rendered['error-401']['body'])
        ->and($fragment['components']['responses']['Forbidden']['content']['application/problem+json']['example'])->toBe($rendered['error-403-scope']['body'])
        ->and($fragment['components']['responses']['NotFound']['content']['application/problem+json']['example'])->toBe($rendered['error-404']['body'])
        ->and($fragment['components']['responses']['Unprocessable']['content']['application/problem+json']['example'])->toBe($rendered['error-422']['body'])
        ->and($fragment['components']['responses']['TooManyRequests']['content']['application/problem+json']['example'])->toBe($rendered['error-429']['body'])
        ->and($fragment['paths']['/v1/vin/{vin}']['get']['responses'][400]['content']['application/problem+json']['example'])->toBe($rendered['error-400-vin']['body'])
        ->and($fragment['components']['responses']['MakeList']['content']['application/json']['example'])->toBe($rendered['makes']['body'])
        // The first example of an operation and status wins: the slug form, not the id form.
        ->and($fragment['paths']['/v1/makes/{key}']['get']['responses'][200]['content']['application/json']['example']['data']['slug'])->toBe('dacia')
        // Nothing is attached beside a $ref, and no body-less or non-JSON example is attached.
        ->and($fragment['paths']['/v1/makes'] ?? null)->toBeNull()
        ->and($fragment['paths']['/openapi.yaml'] ?? null)->toBeNull()
        ->and($fragment['paths']['/v1/snapshots/{resource}'] ?? null)->toBeNull();
});

it('the committed examples.yaml equals a fresh fragment, byte for byte', function (): void {
    $renderer = app(ExampleRenderer::class);
    expect(file_get_contents(PackagePaths::examplesFragment()))->toBe(ExampleRenderer::dumpFragment($renderer->fragment($renderer->render())));
});

it('refuses to write the assembled document into the package resources', function (string $target): void {
    $file = base_path($target);
    $before = is_file($file) ? file_get_contents($file) : null;
    $this->artisan('vehicle:examples', ['action' => 'render', '--openapi-out' => $target])
        ->expectsOutputToContain('must not write into packages/vehicle-data-core/resources')
        ->assertExitCode(1);
    expect(is_file($file) ? file_get_contents($file) : null)->toBe($before);
})->with([
    'among the examples' => 'packages/vehicle-data-core/resources/examples/assembled.yaml',
    'over the contract' => 'packages/vehicle-data-core/resources/openapi/openapi.yaml',
    'over the fragment' => 'packages/vehicle-data-core/resources/openapi/examples.yaml',
    'the resources root' => 'packages/vehicle-data-core/resources/assembled.yaml',
]);

it('--check fails on formatting drift in examples.yaml and passes on the committed file', function (): void {
    $committed = (string) file_get_contents(PackagePaths::examplesFragment());
    $drifted = tempnam(sys_get_temp_dir(), 'fragment');
    $out = sys_get_temp_dir().'/assembled-'.getmypid().'.yaml';
    try {
        // Same data, different bytes: a re-dump with another indentation still parses equal. The
        // renderer compares a temporary copy; the committed file is never touched.
        file_put_contents($drifted, Yaml::dump(Yaml::parse($committed), 20, 4));
        app()->instance(ExampleRenderer::class, new ExampleRenderer(app(Kernel::class), $drifted));
        $this->artisan('vehicle:examples', ['action' => 'render', '--check' => true])
            ->expectsOutputToContain('openapi/examples.yaml')
            ->assertExitCode(1);
        app()->forgetInstance(ExampleRenderer::class);

        $this->artisan('vehicle:examples', ['action' => 'render', '--check' => true, '--openapi-out' => $out])
            ->expectsOutputToContain('Examples up to date.')
            ->assertExitCode(0);
        expect(Yaml::parseFile($out)['paths']['/v1/makes/{key}']['get']['responses'][200]['content']['application/json']['example']['data']['slug'])->toBe('dacia')
            ->and(file_get_contents(PackagePaths::examplesFragment()))->toBe($committed);
    } finally {
        unlink($drifted);
        if (is_file($out)) {
            unlink($out);
        }
    }
});

it('--openapi-out assembles the fragment of this render, not the fragment file', function (): void {
    $stale = tempnam(sys_get_temp_dir(), 'fragment');
    $out = sys_get_temp_dir().'/assembled-fresh-'.getmypid().'.yaml';
    // No --check on this call, so the command also calls save(): point it at a scratch
    // directory (not PackagePaths::examples()) so it never touches the committed
    // examples, and stale() never deletes a real file (M7). uniqid(), not just the pid, so two
    // runs of this test in the same process (e.g. a retry) never collide on the same directory.
    $dir = sys_get_temp_dir().'/examples-'.getmypid().'-'.uniqid();
    if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
        throw new RuntimeException("Could not create scratch directory $dir");
    }
    try {
        // The renderer's fragment file has no examples at all, and --openapi is not given, so
        // the file is neither read nor rewritten: the examples come from this render. (The JSON
        // examples are rewritten with identical bytes; the first test proves they match.)
        file_put_contents($stale, "components: {}\n");
        app()->instance(ExampleRenderer::class, new ExampleRenderer(app(Kernel::class), $stale, $dir));
        $this->artisan('vehicle:examples', ['action' => 'render', '--openapi-out' => $out])->assertExitCode(0);
        $doc = Yaml::parseFile($out);
        expect($doc['components']['schemas']['Make']['example']['slug'])->toBe('dacia')
            ->and($doc['paths']['/v1/makes/{key}']['get']['responses'][200]['content']['application/json']['example']['data']['slug'])->toBe('dacia')
            ->and(file_get_contents($stale))->toBe("components: {}\n")
            ->and(is_file("$dir/make-dacia.json"))->toBeTrue();
    } finally {
        unlink($stale);
        if (is_file($out)) {
            unlink($out);
        }
        // Every file in the scratch directory (it's flat — save() only ever writes .json files
        // there, no subdirectories), not just the ones this test happens to assert on.
        foreach (glob("$dir/*") ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
});

it('refuses an --openapi-out path that reaches the package resources through another spelling or a symlink', function (): void {
    $contract = PackagePaths::openApi();
    $before = file_get_contents($contract);
    $resources = PackagePaths::resources();

    // An upper-case spelling of the resources directory: on a case-insensitive filesystem it is
    // the same directory (refused by identity); on a case-sensitive one it does not exist.
    $upper = dirname($resources).'/RESOURCES/openapi/openapi.yaml';
    $caseInsensitive = is_dir(dirname($upper));
    $this->artisan('vehicle:examples', ['action' => 'render', '--openapi-out' => $upper])
        ->expectsOutputToContain($caseInsensitive ? 'must not write into' : 'does not exist')
        ->assertExitCode(1);

    // A symlinked directory that resolves into the resources, and a symlink file pointing at the contract.
    $scratch = sys_get_temp_dir().'/openapi-out-'.getmypid().'-'.uniqid();
    mkdir($scratch);
    try {
        symlink($resources, "$scratch/linked-dir");
        $this->artisan('vehicle:examples', ['action' => 'render', '--openapi-out' => "$scratch/linked-dir/openapi/openapi.yaml"])
            ->expectsOutputToContain('must not write into')
            ->assertExitCode(1);
        symlink($contract, "$scratch/linked.yaml");
        $this->artisan('vehicle:examples', ['action' => 'render', '--openapi-out' => "$scratch/linked.yaml"])
            ->expectsOutputToContain('must not be a symbolic link')
            ->assertExitCode(1);
    } finally {
        foreach (["$scratch/linked-dir", "$scratch/linked.yaml"] as $link) {
            if (is_link($link)) {
                unlink($link);
            }
        }
        rmdir($scratch);
    }
    expect(file_get_contents($contract))->toBe($before);
});

it('refuses to run in production without --force', function (): void {
    app()->instance('env', 'production');
    try {
        $this->artisan('vehicle:examples', ['action' => 'render', '--check' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertExitCode(1);
        $this->artisan('vehicle:examples', ['action' => 'render', '--check' => true, '--force' => true])
            ->expectsOutputToContain('Examples up to date.')
            ->assertExitCode(0);
    } finally {
        app()->instance('env', 'testing');
    }
});

it('builds a replayable request only while running tests, because it mints a key outside any transaction', function (): void {
    $req = ['name' => 'make-dacia', 'operation' => '/v1/makes/{key}', 'path' => '/v1/makes/dacia', 'scopes' => ['catalogue:read'], 'expect' => 200];
    $clients = ApiClient::query()->count();
    app()->instance('env', 'local');
    try {
        expect(fn () => app(ExampleRenderer::class)->request($req))->toThrow(LogicException::class, 'tests only');
    } finally {
        app()->instance('env', 'testing');
    }
    expect(ApiClient::query()->count())->toBe($clients);
});

it('renders --openapi even when the committed examples.yaml is stale', function (): void {
    // A fragment naming a route the contract does not have: the assembler that reads it throws,
    // so /openapi.yaml would answer 500 and block the very render that regenerates the file.
    $stale = tempnam(sys_get_temp_dir(), 'stale');
    file_put_contents($stale, "paths:\n  /v1/no-such-route:\n    get:\n      responses:\n        \"200\":\n          example: 1\n");
    $fragment = tempnam(sys_get_temp_dir(), 'fragment');
    $dir = sys_get_temp_dir().'/examples-stale-'.getmypid().'-'.uniqid();
    mkdir($dir);
    $withStale = new OpenApiAssembler(app(KindRegistry::class), PackagePaths::openApi(), $stale);
    app()->instance(OpenApiAssembler::class, $withStale);
    try {
        $this->get('/openapi.yaml')->assertStatus(500);
        app()->instance(ExampleRenderer::class, new ExampleRenderer(app(Kernel::class), $fragment, $dir));
        $this->artisan('vehicle:examples', ['action' => 'render', '--openapi' => true])->assertExitCode(0);
        expect(file_get_contents($fragment))->toBe(file_get_contents(PackagePaths::examplesFragment()))
            ->and(is_file("$dir/openapi.json"))->toBeTrue()
            ->and(app(OpenApiAssembler::class))->toBe($withStale);
    } finally {
        app()->forgetInstance(OpenApiAssembler::class);
        app()->forgetInstance(ExampleRenderer::class);
        unlink($stale);
        unlink($fragment);
        foreach (glob("$dir/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
