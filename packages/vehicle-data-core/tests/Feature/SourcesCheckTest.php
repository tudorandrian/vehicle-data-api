<?php

declare(strict_types=1);

use VehicleData\Core\Database\Seeders\ExampleDataSeeder;
use VehicleData\Core\Models\Source;

const FIXTURE_FILES = ['eea_ro_2024.json', 'ro_fleet_2025.csv', 'vpic_wmi.json', 'wikidata_manufacturers.json'];

function sourcesDoc(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'ds').'.md';
    file_put_contents($path, $content);

    return $path;
}

it('passes when every source has a licence and attribution and every fixture is documented', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $doc = sourcesDoc(implode("\n", FIXTURE_FILES));

    $this->artisan('vehicle:sources', ['action' => 'check', '--doc' => $doc])->assertExitCode(0);
});

it('fails and names the source when its attribution is empty', function (): void {
    $this->seed(ExampleDataSeeder::class);
    Source::query()->where('key', 'wmi')->update(['attribution' => '']);
    $doc = sourcesDoc(implode("\n", FIXTURE_FILES));

    $this->artisan('vehicle:sources', ['action' => 'check', '--doc' => $doc])
        ->expectsOutputToContain('wmi: missing attribution')
        ->assertExitCode(1);
});

it('fails for a licence outside the admitted list', function (): void {
    $this->seed(ExampleDataSeeder::class);
    Source::query()->where('key', 'eea')->update(['licence_id' => 'CC-BY-SA-4.0']);
    $doc = sourcesDoc(implode("\n", FIXTURE_FILES));

    $this->artisan('vehicle:sources', ['action' => 'check', '--doc' => $doc])
        ->expectsOutputToContain('eea: licence CC-BY-SA-4.0 is not admitted')
        ->assertExitCode(1);
});

it('fails when a fixture file is not named in the data-sources document', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $doc = sourcesDoc('eea_ro_2024.json ro_fleet_2025.csv wikidata_manufacturers.json');

    $this->artisan('vehicle:sources', ['action' => 'check', '--doc' => $doc])
        ->expectsOutputToContain('fixture vpic_wmi.json is not named in')
        ->assertExitCode(1);
});

it('fails when there are no sources at all', function (): void {
    $doc = sourcesDoc(implode("\n", FIXTURE_FILES));

    $this->artisan('vehicle:sources', ['action' => 'check', '--doc' => $doc])
        ->expectsOutputToContain('no sources registered')
        ->assertExitCode(1);
});

it('lists every fixture file with its SHA-256 and row count in the report', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $out = sys_get_temp_dir().'/data-sources-fixture-list.md';
    $this->artisan('vehicle:sources', ['action' => 'report', '--out' => $out])->assertExitCode(0);
    $md = (string) file_get_contents($out);

    $dir = dirname(__DIR__, 2).'/database/fixtures/';
    expect($md)->toContain('## Fixtures')
        ->toContain('| eea_ro_2024.json | '.hash_file('sha256', $dir.'eea_ro_2024.json').' | 182 |')
        ->toContain('| vpic_wmi.json | '.hash_file('sha256', $dir.'vpic_wmi.json').' | 200 |')
        ->toContain('| wikidata_manufacturers.json | '.hash_file('sha256', $dir.'wikidata_manufacturers.json').' | 33 |')
        ->toContain('| ro_fleet_2025.csv | '.hash_file('sha256', $dir.'ro_fleet_2025.csv').' | 195 |');

    // The report it writes must itself satisfy the check.
    $this->artisan('vehicle:sources', ['action' => 'check', '--doc' => $out])->assertExitCode(0);
});

it('accepts the committed docs/data-sources.md', function (): void {
    $this->seed(ExampleDataSeeder::class);

    $this->artisan('vehicle:sources', ['action' => 'check'])->assertExitCode(0);
});
