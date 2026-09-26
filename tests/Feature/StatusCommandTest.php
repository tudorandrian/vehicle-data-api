<?php

declare(strict_types=1);
use VehicleData\Core\Database\Seeders\ExampleDataSeeder;

it('prints the weekly status summary', function (): void {
    $this->seed(ExampleDataSeeder::class);
    $this->artisan('vehicle:status')->expectsOutputToContain('## Imports')->expectsOutputToContain('| eea |')->expectsOutputToContain('## Catalogue')->assertExitCode(0);
});
