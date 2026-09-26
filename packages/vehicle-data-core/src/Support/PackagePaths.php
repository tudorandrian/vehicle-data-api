<?php

declare(strict_types=1);

namespace VehicleData\Core\Support;

/**
 * The package's committed resources, located from this file rather than from the application's
 * base path, so they resolve the same way whether the package sits in `packages/` or is installed
 * under `vendor/`. ExampleRenderer, ExamplesCommand and CoreServiceProvider all read them here.
 */
final class PackagePaths
{
    /** The package's `resources` directory. */
    public static function resources(): string
    {
        return dirname(__DIR__, 2).'/resources';
    }

    /** The rendered examples (`*.json`) and the list of requests that renders them (`requests.php`). */
    public static function examples(): string
    {
        return self::resources().'/examples';
    }

    /** The hand-written OpenAPI contract. */
    public static function openApi(): string
    {
        return self::resources().'/openapi/openapi.yaml';
    }

    /** The generated fragment that adds the rendered examples to the served contract. */
    public static function examplesFragment(): string
    {
        return self::resources().'/openapi/examples.yaml';
    }

    /** A path relative to the application's base path when it lies inside it (for messages), else unchanged. */
    public static function display(string $path): string
    {
        $base = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? str_replace('\\', '/', substr($path, strlen($base))) : $path;
    }
}
