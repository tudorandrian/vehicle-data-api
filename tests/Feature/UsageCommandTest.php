<?php

declare(strict_types=1);
use VehicleData\Core\Models\ApiUsageDaily;

it('prints a markdown usage report', function (): void {
    [$client] = keyed();
    ApiUsageDaily::query()->create(['client_id' => $client->id, 'date' => now()->subDay()->toDateString(), 'requests' => 12, 'errors' => 1, 'p95_ms' => 42]);
    $this->artisan('vehicle:usage', ['action' => 'report', '--since' => '7d'])->expectsOutputToContain('| '.$client->name.' |')->expectsOutputToContain('| 12 |')->assertExitCode(0);
});
