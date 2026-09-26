<?php

declare(strict_types=1);

namespace VehicleData\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use VehicleData\Core\Examples\ExampleRenderer;
use VehicleData\Core\Kinds\OpenApiAssembler;
use VehicleData\Core\Support\PackagePaths;

final class ExamplesCommand extends Command
{
    use ConfirmableTrait;

    /** @var string */
    protected $signature = 'vehicle:examples {action : render}
        {--check : Exit 1 if the committed examples or resources/openapi/examples.yaml differ from a fresh render}
        {--openapi : Also write resources/openapi/examples.yaml}
        {--openapi-out= : Write the assembled document (base + kinds + the examples of this render) to this file, e.g. test-results/assembled.yaml}
        {--force : Run even in production}';

    /** @var string */
    protected $description = 'Render the documented example requests against the seeded database into resources/examples (and, with --check, verify the committed files).';

    public function handle(ExampleRenderer $renderer, OpenApiAssembler $assembler): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        if ($this->argument('action') !== 'render') {
            $this->error('Unknown action; use: render');

            return self::FAILURE;
        }
        $check = (bool) $this->option('check');
        if ($check && (bool) $this->option('openapi')) {
            $this->error('--check never writes; drop --openapi.');

            return self::FAILURE;
        }
        $out = $this->outputPath();
        if ($out === false) {
            return self::FAILURE;
        }

        // One render per invocation: the JSON files, the diff, the OpenAPI fragment and the
        // assembled document all use it. $renderer->dir() (not the committed directory directly)
        // so a test can point this command at a scratch directory instead of the real resources.
        $dir = $renderer->dir();
        $examples = $renderer->render();
        $fragment = $renderer->fragment($examples);
        if ($check) {
            $diff = $renderer->diff($dir, $examples);
            if (! $renderer->fragmentIsCurrent($fragment)) {
                $diff[] = 'openapi/examples.yaml';
            }
            if ($diff !== []) {
                $this->error('Examples out of date: '.implode(', ', $diff).'. The check assumes a freshly seeded database (`php artisan migrate:fresh --seed --force`, which wipes it); on one, run `php artisan vehicle:examples render --openapi` and commit.');

                return self::FAILURE;
            }
            $this->info('Examples up to date.');
        } else {
            $renderer->save($dir, $examples);
            $this->info('Rendered '.count($examples).' examples into '.PackagePaths::display($dir));
            if ((bool) $this->option('openapi')) {
                $renderer->writeFragment($fragment);
                $this->info('Wrote '.PackagePaths::display($renderer->fragmentPath()));
            }
        }

        if ($out !== null) {
            // Assembled from the fragment of this render, not the committed file, so the linted
            // document is what `--openapi` would commit (under --check the two are identical).
            file_put_contents($out, $assembler->yaml(ExampleRenderer::BASE_URL, $fragment));
            $this->info("Wrote the assembled document to $out");
        }

        return self::SUCCESS;
    }

    /**
     * The --openapi-out file (relative paths are taken from the project root), null without the
     * option, or false after an error. It may never land in the package's resources: it would
     * overwrite openapi.yaml or examples.yaml, or be taken for a stale example there. The target
     * may not be a symbolic link, and every ancestor of its resolved directory is compared with
     * the resources directory by filesystem identity, not by spelling, so a different letter case
     * on a case-insensitive filesystem or a symlinked parent directory cannot slip past.
     */
    private function outputPath(): string|false|null
    {
        $out = $this->option('openapi-out');
        if (! is_string($out) || $out === '') {
            return null;
        }
        $path = preg_match('~^([A-Za-z]:)?[\\\\/]~', $out) === 1 ? $out : base_path($out);
        if (is_link($path)) {
            $this->error('--openapi-out must not be a symbolic link: '.$path);

            return false;
        }
        $dir = realpath(dirname($path));
        if ($dir === false) {
            $this->error('The directory of --openapi-out does not exist: '.dirname($path));

            return false;
        }
        $resources = PackagePaths::resources();
        for ($ancestor = $dir; ; $ancestor = dirname($ancestor)) {
            if (self::sameDirectory($ancestor, $resources)) {
                $this->error('--openapi-out must not write into '.PackagePaths::display($resources).'; use test-results/.');

                return false;
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }

        return $dir.DIRECTORY_SEPARATOR.basename($path);
    }

    /**
     * Same device and inode. Where the filesystem reports no inode, the resolved paths are
     * compared instead, case-insensitively on the case-insensitive platforms (Windows, macOS).
     */
    private static function sameDirectory(string $a, string $b): bool
    {
        $sa = @stat($a);
        $sb = @stat($b);
        if ($sa === false || $sb === false) {
            return false;
        }
        if ($sa['ino'] !== 0 && $sb['ino'] !== 0) {
            return $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino'];
        }
        $ra = (string) realpath($a);
        $rb = (string) realpath($b);

        return in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true) ? strcasecmp($ra, $rb) === 0 : $ra === $rb;
    }
}
