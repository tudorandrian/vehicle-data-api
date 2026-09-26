<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('serves the assembled document anonymously as YAML with an ETag', function (): void {
    $res = $this->get('/openapi.yaml')->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($res->getContent())->toContain('openapi: 3.0.3')->toContain('CarSpecifications')
        ->and($res->headers->get('ETag'))->not->toBeNull()
        ->and(Yaml::parse((string) $res->getContent())['components']['schemas']['Specifications']['anyOf'])
        ->toBe([['$ref' => '#/components/schemas/CarSpecifications']])
        // An absolute server URL: a relative "/" makes UI clients build "//v1/…", which 404s.
        ->and(Yaml::parse((string) $res->getContent())['servers'])->toBe([['url' => url('/'), 'description' => 'This deployment']]);

    $this->get('/openapi.yaml', ['If-None-Match' => (string) $res->headers->get('ETag')])->assertStatus(304);
});

it('serves the examples with quoted status keys and nothing beside a response $ref', function (): void {
    $yaml = (string) $this->get('/openapi.yaml')->assertOk()->getContent();
    expect($yaml)->toContain('"200":')->toContain('"401":')->toContain('example:')
        ->and(preg_match('/^\s*\d+:/m', $yaml))->toBe(0);
    $doc = Yaml::parse($yaml);
    foreach ($doc['paths'] as $path => $item) {
        foreach ($item['get']['responses'] as $status => $response) {
            if (isset($response['$ref'])) {
                expect(array_keys($response))->toBe(['$ref'], "keys beside the \$ref of $path $status");
            }
        }
    }
    expect($doc['paths']['/v1/makes/{key}']['get']['responses']['200']['content']['application/json']['example']['data']['slug'])->toBe('dacia');
});
