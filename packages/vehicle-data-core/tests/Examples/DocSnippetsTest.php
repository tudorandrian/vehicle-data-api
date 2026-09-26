<?php

declare(strict_types=1);

use VehicleData\Core\Tests\Examples\DocSnippetChecker;

it('every marked snippet in README.md and docs/api.md matches its rendered example', function (string $doc): void {
    $text = (string) file_get_contents(base_path($doc));
    $problems = DocSnippetChecker::check($text, $doc);
    expect($problems)->toBe([]);
})->with(['README.md', 'docs/api.md']);

it('the checker fails a snippet that has drifted from its rendered example', function (): void {
    $markdown = "<!-- example: health -->\n```json\n{\n    \"data\": {\n        \"status\": \"drifted\"\n    }\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: snippet health differs; regenerate it from packages/vehicle-data-core/resources/examples']);
});

it('the checker rejects a partial block made entirely of elided lines', function (): void {
    $markdown = "<!-- example: health partial -->\n```json\n{\n    …\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: partial snippet health has no non-elided line with a JSON key or value']);
});

it('the checker reports an unknown example name instead of a PHP warning', function (): void {
    $markdown = "<!-- example: no-such-example -->\n```json\n{}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown refers to unknown example no-such-example']);
});

it('the checker reports an unknown path segment instead of a PHP warning', function (): void {
    $markdown = "<!-- example: health path=data.no_such_key -->\n```json\nnull\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(["markdown: snippet health refers to unknown path segment 'no_such_key' (in path=data.no_such_key)"]);
});

it('the checker passes a snippet that matches its rendered example', function (): void {
    $markdown = "<!-- example: health -->\n```json\n{\n    \"data\": {\n        \"status\": \"ok\",\n        \"version\": \"dev\",\n        \"time\": \"2026-01-01T00:00:00+00:00\"\n    }\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe([]);
});

// M2: exact message for a partial line that is simply missing from the encoding at path.
it('the checker reports exactly which partial line is missing', function (): void {
    $markdown = "<!-- example: health partial path=data -->\n```json\n{\n    \"status\": \"ok\",\n    \"nope\": \"not in the file\"\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: partial snippet health, line not found:     "nope": "not in the file"']);
});

// M2: path scoping - `"licence": "CC-BY-4.0"` is a real line of make-dacia.json's body (inside
// `sources[0]`), but not inside `data`, so a partial block at path=data that quotes it must
// fail: proof that each line is checked against the encoding AT PATH, not the whole file.
it('the checker fails a partial line that exists elsewhere in the file but not at path', function (): void {
    $markdown = "<!-- example: make-dacia partial path=data -->\n```json\n{\n    \"slug\": \"dacia\",\n    \"licence\": \"CC-BY-4.0\"\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: partial snippet make-dacia, line not found:     "licence": "CC-BY-4.0"']);
});

// M1: the trailing comma on either side of the comparison is tolerated.
it('the checker tolerates an optional trailing comma on a partial line', function (): void {
    $markdown = "<!-- example: health partial path=data -->\n```json\n{\n    \"status\": \"ok\",\n    \"version\": \"dev\"\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe([]);
});

// I6: a marker not immediately followed by its fence (here, a blank line in between) must be
// reported as a stray marker, never silently skipped.
it('the checker reports a marker with a blank line before its fence instead of skipping it', function (): void {
    $markdown = "<!-- example: health -->\n\n```json\n{\n    \"data\": {}\n}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: marker for health (example) has no fenced block directly beneath it']);
});

// I3/I5: the `request:` marker checks the fence's first line against the rendered file's own
// path + query, built the same way ExampleRenderer::prepare() builds the real request URI.
it('the checker passes a request snippet whose first line matches path and query', function (): void {
    $markdown = "<!-- request: taxonomy-national-category-en -->\n```http\nGET /v1/taxonomies/national_category?lang=en HTTP/1.1\nAuthorization: Bearer vd_live_…\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe([]);
});

it('the checker fails a request snippet whose first line has drifted', function (): void {
    $markdown = "<!-- request: health -->\n```http\nGET /v1/health?wrong=1 HTTP/1.1\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(["markdown: request snippet health, first line should be 'GET /v1/health HTTP/1.1', got 'GET /v1/health?wrong=1 HTTP/1.1'"]);
});

it('the checker rejects partial or path= on a request marker', function (): void {
    $markdown = "<!-- request: health partial -->\n```http\nGET /v1/health HTTP/1.1\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: request marker for health must not use partial or path=']);
});

it('the checker fails a request snippet whose id is wrong', function (): void {
    $markdown = "<!-- request: make-dacia-by-id -->\n```http\nGET /v1/makes/WRONGWRONGWRONGWRONGWRONGW HTTP/1.1\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(["markdown: request snippet make-dacia-by-id, first line should be 'GET /v1/makes/JA47BF995RFMPBB3ECP36BM0QR HTTP/1.1', got 'GET /v1/makes/WRONGWRONGWRONGWRONGWRONGW HTTP/1.1'"]);
});

it('the checker fails a request snippet whose query parameters are reordered', function (): void {
    // The real query is {sort, per_page}, in that order; http_build_query() preserves it, so
    // swapping the two must fail even though the parameters and values are individually correct.
    $markdown = "<!-- request: make-dacia-models -->\n```http\nGET /v1/makes/dacia/models?per_page=3&sort=-ro_fleet_count HTTP/1.1\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(["markdown: request snippet make-dacia-models, first line should be 'GET /v1/makes/dacia/models?sort=-ro_fleet_count&per_page=3 HTTP/1.1', got 'GET /v1/makes/dacia/models?per_page=3&sort=-ro_fleet_count HTTP/1.1'"]);
});

// I6 remainder: a marker that fails the MARKER regex itself (not just the fence check after it)
// must still be reported, not silently skipped because it never became a match at all.
it('the checker reports a marker with partial and path= in the wrong order', function (): void {
    $markdown = "<!-- example: health path=data partial -->\n```json\n{}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: malformed marker, does not match `<!-- example: <name> [partial] [path=<dot.path>] -->` or `<!-- request: <name> -->`: <!-- example: health path=data partial -->']);
});

it('the checker reports a marker with no space before -->', function (): void {
    $markdown = "<!-- example: health partial path=data-->\n```json\n{}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: malformed marker, does not match `<!-- example: <name> [partial] [path=<dot.path>] -->` or `<!-- request: <name> -->`: <!-- example: health partial path=data-->']);
});

it('the checker reports a marker with no space after <!--', function (): void {
    $markdown = "<!--example: health -->\n```json\n{}\n```\n";
    expect(DocSnippetChecker::check($markdown))->toBe(['markdown: malformed marker, does not match `<!-- example: <name> [partial] [path=<dot.path>] -->` or `<!-- request: <name> -->`: <!--example: health -->']);
});
