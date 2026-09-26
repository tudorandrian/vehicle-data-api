<?php

declare(strict_types=1);

namespace VehicleData\Core\Kinds;

use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Yaml\Yaml;

final class OpenApiAssembler
{
    /**
     * @param  string|null  $examplesPath  The generated examples fragment (`vehicle:examples render --openapi`);
     *                                     skipped when null or absent.
     */
    public function __construct(private readonly KindRegistry $kinds, private readonly string $basePath, private readonly ?string $examplesPath = null) {}

    /**
     * @param  array<string, mixed>|null  $examples  an examples fragment to merge instead of the file at $examplesPath
     *                                               (`vehicle:examples render --openapi-out` passes the one it just built)
     * @return array<string,mixed>
     */
    public function assemble(?array $examples = null): array
    {
        /** @var array{components: array{schemas: array<string, mixed>}} $doc */
        $doc = Yaml::parseFile($this->basePath);
        $refs = [];
        foreach ($this->kinds->kinds() as $kind) {
            $name = Str::studly($kind).'Specifications';
            $doc['components']['schemas'][$name] = $this->kinds->schema($kind)->openApiFragment();
            $refs[] = ['$ref' => '#/components/schemas/'.$name];
        }
        // anyOf, not oneOf: kind fragments are not mutually exclusive (one without
        // additionalProperties: false matches any object), so oneOf would reject
        // valid payloads as soon as a second kind is registered.
        $doc['components']['schemas']['Specifications'] = ['anyOf' => $refs, 'description' => 'Kind-specific specifications; the kind is implied by the make.'];

        if ($examples === null && $this->examplesPath !== null && is_file($this->examplesPath)) {
            /** @var array<string, mixed> $examples */
            $examples = Yaml::parseFile($this->examplesPath);
        }
        if ($examples !== null) {
            $doc = self::addExamples($doc, $examples, '');
        }

        return $doc;
    }

    /**
     * @param  string|null  $serverUrl  Absolute base URL of the deployment serving the document; replaces the
     *                                  base file's relative `/`, which UI clients join into `//v1/…`.
     * @param  array<string, mixed>|null  $examples  see assemble()
     */
    public function yaml(?string $serverUrl = null, ?array $examples = null): string
    {
        $doc = $this->assemble($examples);
        if ($serverUrl !== null) {
            $doc['servers'] = [['url' => rtrim($serverUrl, '/'), 'description' => 'This deployment']];
        }

        return self::dump($doc, 12);
    }

    /** @param array<int|string, mixed> $doc */
    public static function dump(array $doc, int $inline): string
    {
        $yaml = Yaml::dump($doc, $inline, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        // PHP turns the "200" response keys into integers, which Yaml::dump() writes
        // as bare `200:`; OpenAPI requires string keys and strict YAML tooling
        // (Spectral, Scalar) rejects numeric ones, so they are quoted back.
        return (string) preg_replace('/^(\s*)(\d+):/m', '$1"$2":', $yaml);
    }

    /**
     * Merges the examples fragment by adding `example` keys only. Unlike array_replace_recursive,
     * it never creates a node the contract does not have (a schema, a response, a media type or
     * `content` beside a `$ref`), never replaces a contract key, and sets each example whole, so a
     * list inside an example is never merged element-wise with anything.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $doc
     * @param  array<int|string, mixed>  $fragment
     * @return array<TKey|'example', mixed>
     */
    private static function addExamples(array $doc, array $fragment, string $at): array
    {
        foreach ($fragment as $key => $value) {
            $here = $at === '' ? (string) $key : "$at/$key";
            if ($key === 'example') {
                if (array_key_exists('$ref', $doc)) {
                    throw new LogicException("examples.yaml: an example beside a \$ref at $at");
                }
                if (array_key_exists('example', $doc)) {
                    throw new LogicException("examples.yaml: $at already has an example");
                }
                $doc['example'] = $value;

                continue;
            }
            if (! isset($doc[$key]) || ! is_array($doc[$key]) || ! is_array($value)) {
                throw new LogicException("examples.yaml: $here is not in the contract (examples only add `example` keys)");
            }
            $doc[$key] = self::addExamples($doc[$key], $value, $here);
        }

        return $doc;
    }
}
