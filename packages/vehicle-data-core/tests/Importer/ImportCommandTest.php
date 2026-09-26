<?php

declare(strict_types=1);

use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Importers\SourceRegistry;

it('fails cleanly with a message listing valid keys when the source is unknown', function (): void {
    $this->artisan('vehicle:import', ['source' => 'bogus'])
        ->expectsOutputToContain('Unknown data source [bogus]')
        ->assertExitCode(1);
});

it('exits 1 with the licence named when the source is not admitted', function (): void {
    $source = new class implements DataSource
    {
        public function key(): string
        {
            return 'sa';
        }

        public function name(): string
        {
            return 'Share-alike';
        }

        public function url(): string
        {
            return 'https://example.org/sa';
        }

        public function licence(): Licence
        {
            return new Licence('CC-BY-SA-4.0', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', 'x');
        }

        public function fetch(ImportOptions $o): iterable
        {
            yield from [];
        }

        public function map(RawRow $r): ?DomainRow
        {
            return null;
        }

        public function naturalKey(DomainRow $row): string
        {
            return '';
        }
    };

    // Rebind the singleton for this test only (the container is rebuilt per test, so this
    // never leaks into another test) - keep the real registered sources (via the registry's own
    // public accessors, not a hard-coded roster) and add 'sa' alongside them.
    $registry = app(SourceRegistry::class);
    $sources = [];
    foreach ($registry->keys() as $key) {
        $sources[$key] = $registry->get($key);
    }
    $sources['sa'] = $source;
    app()->instance(SourceRegistry::class, new SourceRegistry($sources));

    $this->artisan('vehicle:import', ['source' => 'sa'])->expectsOutputToContain('CC-BY-SA-4.0')->assertExitCode(1);
});
