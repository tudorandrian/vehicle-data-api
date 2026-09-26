<?php

declare(strict_types=1);

namespace VehicleData\Core\Tests\Examples;

use VehicleData\Core\Support\PackagePaths;

/**
 * Checks that every marked snippet in a Markdown document matches the rendered example file it
 * names. Shared by DocSnippetsTest (which checks the real docs) and its own negative tests
 * (which feed it in-memory strings), so the checking logic itself is proven to fail on drift,
 * not just trusted to pass on the committed docs.
 *
 * Two marker types, each on the line directly above its fence (no blank line in between):
 *
 * - `<!-- example: <name> [partial] [path=<dot.path>] -->` above a ```json, ```http or ```csv
 *   fence. Without `partial`, the block must equal the encoding of the example's `body` at
 *   `path`: JSON (`JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`) for an
 *   array/object body, or the trimmed text (BOM stripped) for a string body such as CSV. With
 *   `partial`, every line of the block is checked against the SET of that encoding's own lines
 *   (each compared with an optional trailing comma tolerated on either side): a line that is
 *   exactly `…` or `…,` is skipped, every other line must be a member of that set, and at least
 *   one such line must carry a JSON key or value (not just structural punctuation), so a block
 *   made entirely of `…` is rejected.
 * - `<!-- request: <name> -->` above a ```http fence. The fence's first line must equal
 *   `GET <path>` plus, if the rendered file recorded one, `?<query>` - built and encoded exactly
 *   as ExampleRenderer::prepare() builds the real request URI - followed by ` HTTP/1.1`. This
 *   marker never takes `partial` or `path=`.
 *
 * Every marker occurrence found in the text is walked and checked; one that is not immediately
 * followed by a well-formed fence of the right kind (for example because of a blank line, or an
 * unsupported fence language) is reported as a problem, never silently skipped. The same is true
 * of a marker that fails the marker syntax itself (a missing space before `-->`, `partial` and
 * `path=` in the wrong order, `<!--example:` with no space): a separate, looser scan of every
 * `<!-- example`/`<!-- request` occurrence catches anything the strict marker pattern missed.
 */
final class DocSnippetChecker
{
    private const MARKER = '/<!-- (example|request): (\S+)( partial)?(?: path=(\S+))? -->/';

    /** Deliberately loose: finds every occurrence that is trying to be a marker, well-formed or not. */
    private const LOOSE_MARKER = '/<!--\s*(example|request)\b/';

    private const EXAMPLE_FENCE = '/\A\n```(?:json|http|csv)\n(.*?)\n```/s';

    private const REQUEST_FENCE = '/\A\n```http\n(.*?)\n```/s';

    /** @return list<string> problems found; empty when every marked snippet matches */
    public static function check(string $markdown, string $label = 'markdown'): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);

        preg_match_all(self::MARKER, $markdown, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        preg_match_all(self::LOOSE_MARKER, $markdown, $loose, PREG_OFFSET_CAPTURE);

        if ($markers === [] && $loose[0] === []) {
            return ["$label has no marked snippets"];
        }

        // Both patterns start their match at the same `<!--`, so a marker the strict pattern
        // matched has a loose match at the identical offset; one whose syntax is malformed
        // (reversed `partial`/`path=`, a missing space before `-->`, `<!--example:` with none
        // after `<!--`, …) shows up in the loose scan with no strict match at that offset.
        $strictOffsets = array_map(static fn (array $m): int => $m[0][1], $markers);
        $problems = [];
        foreach ($loose[0] as [, $offset]) {
            if (! in_array($offset, $strictOffsets, true)) {
                $problems[] = "$label: malformed marker, does not match ".
                    '`<!-- example: <name> [partial] [path=<dot.path>] -->` or `<!-- request: <name> -->`: '.
                    self::lineAt($markdown, $offset);
            }
        }

        foreach ($markers as $m) {
            $problem = self::checkMarker($label, $markdown, $m);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private static function lineAt(string $markdown, int $offset): string
    {
        $end = strpos($markdown, "\n", $offset);

        return trim($end === false ? substr($markdown, $offset) : substr($markdown, $offset, $end - $offset));
    }

    /** @param  list<array{0: string, 1: int}>  $m  a PREG_SET_ORDER|PREG_OFFSET_CAPTURE match: [whole, type, name, partial?, path?] */
    private static function checkMarker(string $label, string $markdown, array $m): ?string
    {
        $type = $m[1][0];
        $name = $m[2][0];
        $partial = ($m[3][0] ?? '') !== '';
        $path = $m[4][0] ?? '';
        $rest = substr($markdown, $m[0][1] + strlen($m[0][0]));

        if ($type === 'request') {
            if ($partial || $path !== '') {
                return "$label: request marker for $name must not use partial or path=";
            }
            if (! preg_match(self::REQUEST_FENCE, $rest, $fence)) {
                return "$label: marker for $name (request) has no ```http block directly beneath it";
            }

            return self::checkRequest($label, $name, $fence[1]);
        }

        if (! preg_match(self::EXAMPLE_FENCE, $rest, $fence)) {
            return "$label: marker for $name (example) has no fenced block directly beneath it";
        }

        return self::checkExample($label, $name, $partial, $path, $fence[1]);
    }

    private static function checkExample(string $label, string $name, bool $partial, string $path, string $block): ?string
    {
        $file = PackagePaths::examples()."/$name.json";
        if (! is_file($file)) {
            return "$label refers to unknown example $name";
        }
        /** @var array{body: mixed} $decoded */
        $decoded = json_decode((string) file_get_contents($file), true);
        $example = $decoded['body'];
        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            if (! is_array($example) || ! array_key_exists($segment, $example)) {
                return "$label: snippet $name refers to unknown path segment '$segment' (in path=$path)";
            }
            $example = $example[$segment];
        }

        if (is_string($example)) {
            // The rendered CSV body starts with a UTF-8 BOM; the block in the doc never shows
            // it (an invisible BOM pasted into Markdown would be indistinguishable from nothing).
            $expected = trim(ltrim($example, "\xEF\xBB\xBF"));

            return trim($block) === $expected ? null : "$label: snippet $name (string body) differs; regenerate it from ".PackagePaths::display(PackagePaths::examples());
        }

        $expected = (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! $partial) {
            return trim($block) === $expected ? null : "$label: snippet $name differs; regenerate it from ".PackagePaths::display(PackagePaths::examples());
        }

        // The set of the encoding's own lines at `path`, each with an optional trailing comma
        // stripped so a partial block may close a structure early without a comma the full
        // encoding would still have (or vice versa) without that alone failing the check.
        $expectedLines = array_map(static fn (string $l): string => rtrim(trim($l), ','), explode("\n", $expected));
        $sawContent = false;
        foreach (explode("\n", $block) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '…' || $trimmed === '…,') {
                continue;
            }
            $normalised = rtrim($trimmed, ',');
            if (! in_array($normalised, $expectedLines, true)) {
                return "$label: partial snippet $name, line not found: $line";
            }
            if (! in_array($normalised, ['{', '}', '[', ']'], true)) {
                $sawContent = true;
            }
        }

        return $sawContent ? null : "$label: partial snippet $name has no non-elided line with a JSON key or value";
    }

    private static function checkRequest(string $label, string $name, string $block): ?string
    {
        $file = PackagePaths::examples()."/$name.json";
        if (! is_file($file)) {
            return "$label refers to unknown example $name";
        }
        /** @var array{path: string, query: array<string, string>} $decoded */
        $decoded = json_decode((string) file_get_contents($file), true);
        $path = $decoded['path'];
        $query = $decoded['query'];
        if ($query !== []) {
            // The same encoding ExampleRenderer::prepare() uses to build the real request URI.
            $path .= '?'.http_build_query($query);
        }
        $expected = "GET $path HTTP/1.1";
        $firstLine = trim(explode("\n", $block, 2)[0]);

        return $firstLine === $expected ? null : "$label: request snippet $name, first line should be '$expected', got '$firstLine'";
    }
}
