<?php

declare(strict_types=1);

use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Database\Seeders\SourceSeeder;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Importers\SourceRegistry;
use VehicleData\Core\Models\Source;

function fakeRegistrySource(string $key, string $name, string $url, Licence $licence): DataSource
{
    return new class($key, $name, $url, $licence) implements DataSource
    {
        public function __construct(private string $key, private string $name, private string $url, private Licence $licence) {}

        public function key(): string
        {
            return $this->key;
        }

        public function name(): string
        {
            return $this->name;
        }

        public function url(): string
        {
            return $this->url;
        }

        public function licence(): Licence
        {
            return $this->licence;
        }

        public function fetch(ImportOptions $options): iterable
        {
            return [];
        }

        public function map(RawRow $row): ?DomainRow
        {
            return null;
        }

        public function naturalKey(DomainRow $row): string
        {
            return '';
        }
    };
}

it('seeds sources from the registered SourceRegistry, with names, urls and licences read from each source itself', function (): void {
    app()->singleton(SourceRegistry::class, fn () => new SourceRegistry([
        'fake-a' => fakeRegistrySource('fake-a', 'Fake A', 'https://example.org/a', Licence::cc0()),
        'fake-b' => fakeRegistrySource('fake-b', 'Fake B', 'https://example.org/b', Licence::eea()),
    ]));

    $this->seed(SourceSeeder::class);

    expect(Source::query()->pluck('key')->sort()->values()->all())->toBe(['fake-a', 'fake-b']);
    Source::query()->each(fn ($s) => expect($s->attribution)->not->toBe('')->and($s->licence_url)->toStartWith('https://'));

    $a = Source::query()->where('key', 'fake-a')->firstOrFail();
    expect($a->name)->toBe('Fake A')->and($a->url)->toBe('https://example.org/a')->and($a->licence_id)->toBe(Licence::cc0()->id);
});

it('seeds nothing when no sources are registered', function (): void {
    // Real app boot registers eea, ro-fleet, wikidata and wmi — so this
    // test rebinds an empty registry explicitly to isolate SourceSeeder's behaviour from that roster.
    app()->singleton(SourceRegistry::class, fn () => new SourceRegistry([]));

    $this->seed(SourceSeeder::class);

    expect(Source::query()->count())->toBe(0);
});
