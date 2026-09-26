<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use VehicleData\Core\Contracts\SpecificationSchema;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Kinds\OpenApiAssembler;
use VehicleData\Core\Kinds\Schemas\CarSpecificationSchema;
use VehicleData\Core\Kinds\SchemaValidator;

it('assembles the base document with one specifications schema per kind', function (): void {
    $doc = app(OpenApiAssembler::class)->assemble();
    expect($doc['openapi'])->toBe('3.0.3')
        ->and($doc['components']['schemas']['Specifications']['anyOf'])->toBe([['$ref' => '#/components/schemas/CarSpecifications']])
        ->and($doc['components']['schemas']['CarSpecifications']['type'])->toBe('object');
});

it('includes a privately registered kind without touching the base file', function (): void {
    app(KindRegistry::class)->register(new class implements SpecificationSchema
    {
        public function kind(): string
        {
            return 'drone';
        }

        public function jsonSchema(): array
        {
            return ['type' => 'object', 'properties' => ['max_takeoff_mass_g' => ['type' => 'integer']], 'additionalProperties' => false];
        }

        public function openApiFragment(): array
        {
            return ['type' => 'object', 'title' => 'DroneSpecifications', 'properties' => ['max_takeoff_mass_g' => ['type' => 'integer']]];
        }
    });
    $doc = app(OpenApiAssembler::class)->assemble();
    expect($doc['components']['schemas'])->toHaveKey('DroneSpecifications')
        ->and($doc['components']['schemas']['Specifications']['anyOf'])->toHaveCount(2);
    $base = file_get_contents(base_path('packages/vehicle-data-core/resources/openapi/openapi.yaml'));
    expect($base)->not->toContain('Drone');
});

it('keeps the base CarSpecifications identical to the car fragment', function (): void {
    /** @var array<string, mixed> $base */
    $base = Yaml::parseFile(base_path('packages/vehicle-data-core/resources/openapi/openapi.yaml'));
    expect($base['components']['schemas']['CarSpecifications'])->toBe((new CarSpecificationSchema)->openApiFragment());
});

it('dumps YAML that parses back to the assembled document with status codes as string keys', function (): void {
    $assembler = app(OpenApiAssembler::class);
    $yaml = $assembler->yaml();
    // OpenAPI requires response codes to be string keys; strict YAML tooling
    // (Spectral, Scalar) rejects a bare `200:` mapping key.
    expect(Yaml::parse($yaml))->toBe($assembler->assemble())
        ->and($yaml)->toContain('"200":')->toContain('"304":')
        ->and(preg_match('/^\s*\d+:/m', $yaml))->toBe(0);
});

it('replaces the relative server with the absolute deployment URL when given one', function (): void {
    $assembler = app(OpenApiAssembler::class);
    expect(Yaml::parse($assembler->yaml())['servers'])->toBe([['url' => '/']])
        ->and(Yaml::parse($assembler->yaml('https://api.example.test/'))['servers'])->toBe([['url' => 'https://api.example.test', 'description' => 'This deployment']]);
});

it('keeps a car payload valid against the served Specifications once a second kind is registered', function (): void {
    // Kind fragments are not mutually exclusive (a fragment without
    // additionalProperties: false, or `{}`, matches any object), so `oneOf`
    // would reject every car payload as soon as a second kind exists.
    app(KindRegistry::class)->register(new class implements SpecificationSchema
    {
        public function kind(): string
        {
            return 'drone';
        }

        public function jsonSchema(): array
        {
            return ['type' => 'object'];
        }

        public function openApiFragment(): array
        {
            return ['type' => 'object', 'title' => 'DroneSpecifications', 'properties' => ['max_takeoff_mass_g' => ['type' => 'integer']]];
        }
    });
    $doc = app(OpenApiAssembler::class)->assemble();
    $schema = ['$ref' => '#/components/schemas/Specifications', 'components' => $doc['components']];

    expect(SchemaValidator::errors(['doors' => 5, 'fuel_mode' => 'M', 'euro_stage_raw' => '6AP'], $schema))->toBe([])
        ->and(SchemaValidator::errors(['max_takeoff_mass_g' => 900], $schema))->toBe([])
        ->and(SchemaValidator::errors(['max_takeoff_mass_g' => 'heavy'], $schema))->not->toBe([]);
});

it('keeps the base Specifications identical to what the assembler serves for the car kind', function (): void {
    /** @var array<string, mixed> $base */
    $base = Yaml::parseFile(base_path('packages/vehicle-data-core/resources/openapi/openapi.yaml'));
    expect($base['components']['schemas']['Specifications'])->toBe(app(OpenApiAssembler::class)->assemble()['components']['schemas']['Specifications']);
});

it('merges the committed examples without changing any other key of the contract', function (): void {
    // Removes every `example` key, i.e. what the examples fragment adds.
    $withoutExamples = function (array $node) use (&$withoutExamples): array {
        unset($node['example']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $withoutExamples($value);
            }
        }

        return $node;
    };
    $base = base_path('packages/vehicle-data-core/resources/openapi/openapi.yaml');
    $without = (new OpenApiAssembler(app(KindRegistry::class), $base))->assemble();
    $with = app(OpenApiAssembler::class)->assemble();
    expect($with)->not->toBe($without)
        ->and(substr_count(json_encode($with, JSON_THROW_ON_ERROR), '"example":'))->toBeGreaterThan(20)
        ->and($withoutExamples($with))->toBe($without);
});

it('refuses an examples fragment that would create a key the contract does not have', function (array $fragment, string $message): void {
    $file = tempnam(sys_get_temp_dir(), 'examples');
    file_put_contents($file, Yaml::dump($fragment, 20, 2));
    $assembler = new OpenApiAssembler(app(KindRegistry::class), base_path('packages/vehicle-data-core/resources/openapi/openapi.yaml'), $file);
    try {
        expect(fn () => $assembler->assemble())->toThrow(LogicException::class, $message);
    } finally {
        unlink($file);
    }
})->with([
    'an unknown schema' => [['components' => ['schemas' => ['NoSuchSchema' => ['example' => ['a' => 1]]]]], 'components/schemas/NoSuchSchema'],
    'content beside a $ref' => [['paths' => ['/v1/makes' => ['get' => ['responses' => [401 => ['content' => ['application/problem+json' => ['example' => ['a' => 1]]]]]]]]], 'paths//v1/makes/get/responses/401/content'],
    'an undeclared media type' => [['paths' => ['/v1/health' => ['get' => ['responses' => [200 => ['content' => ['text/plain' => ['example' => 'ok']]]]]]]], 'paths//v1/health/get/responses/200/content/text/plain'],
    'an example beside a $ref' => [['paths' => ['/v1/makes' => ['get' => ['responses' => [401 => ['example' => ['a' => 1]]]]]]], 'beside a $ref'],
    'a replaced contract key' => [['components' => ['schemas' => ['Make' => ['example' => ['a' => 1], 'type' => 'string']]]], 'components/schemas/Make/type'],
]);
