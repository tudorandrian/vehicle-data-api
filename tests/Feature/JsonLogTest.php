<?php

declare(strict_types=1);
use Monolog\Formatter\JsonFormatter;
use VehicleData\Core\Models\ApiRequest;

it('writes one JSON line per request with the request id and the client prefix, never the key', function (): void {
    config()->set('logging.default', 'testlog');
    config()->set('logging.channels.testlog', ['driver' => 'single', 'path' => $path = storage_path('logs/test-'.uniqid().'.log'), 'formatter' => JsonFormatter::class]);
    [$client, $key] = keyed();
    $res = $this->getJson('/v1/health/ready', bearer($key));
    $line = collect(file($path))->first(fn ($l) => str_contains($l, 'api.request'));
    $json = json_decode((string) $line, true);
    expect($json['context'])->toMatchArray(['request_id' => $res->headers->get('X-Request-Id'), 'client_prefix' => $client->key_prefix, 'route' => 'v1.health.ready', 'status' => 200])
        ->and((string) $line)->not->toContain($key);
    @unlink($path);
});

it('logs "unmatched" rather than the raw path for a 404 under /v1, so a VIN-like path never reaches the log', function (): void {
    config()->set('logging.default', 'testlog');
    config()->set('logging.channels.testlog', ['driver' => 'single', 'path' => $path = storage_path('logs/test-'.uniqid().'.log'), 'formatter' => JsonFormatter::class]);

    $res = $this->getJson('/v1/no-such-route/RAWVINVALUE123456')->assertStatus(404);

    $line = collect(file($path))->first(fn ($l) => str_contains($l, 'api.request'));
    $json = json_decode((string) $line, true);
    expect($json['context'])->toMatchArray([
        'request_id' => $res->headers->get('X-Request-Id'),
        'route' => 'unmatched',
        'status' => 404,
    ])->and((string) $line)->not->toContain('RAWVINVALUE123456');
    @unlink($path);
});

it('logs the anonymous v1/health route without recording a usage row or leaking a key', function (): void {
    config()->set('logging.default', 'testlog');
    config()->set('logging.channels.testlog', ['driver' => 'single', 'path' => $path = storage_path('logs/test-'.uniqid().'.log'), 'formatter' => JsonFormatter::class]);

    $res = $this->getJson('/v1/health');

    $line = collect(file($path))->first(fn ($l) => str_contains($l, 'api.request'));
    $json = json_decode((string) $line, true);
    expect($json['context'])->toMatchArray([
        'request_id' => $res->headers->get('X-Request-Id'),
        'client_prefix' => null,
        'route' => 'v1.health',
        'status' => 200,
    ])->and(ApiRequest::query()->where('route', 'v1.health')->count())->toBe(0);
    @unlink($path);
});
