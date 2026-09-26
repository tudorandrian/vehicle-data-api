<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use VehicleData\Core\Database\Factories\ApiClientFactory;
use VehicleData\Core\Models\ApiClient;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../packages/vehicle-data-core/tests/Feature', '../packages/vehicle-data-core/tests/Contract',
        '../packages/vehicle-data-core/tests/Importer', '../packages/vehicle-data-core/tests/Seed', '../packages/vehicle-data-core/tests/Live',
        '../packages/vehicle-data-core/tests/Examples');

pest()->extend(TestCase::class)->in('Unit', '../packages/vehicle-data-core/tests/Unit');

pest()->group('contract')->in('../packages/vehicle-data-core/tests/Contract');
pest()->group('importer')->in('../packages/vehicle-data-core/tests/Importer');
pest()->group('seed')->in('../packages/vehicle-data-core/tests/Seed');
pest()->group('network')->in('../packages/vehicle-data-core/tests/Live');
pest()->group('examples')->in('../packages/vehicle-data-core/tests/Examples');

/**
 * Creates a client with the given scopes and returns [client, plaintextKey].
 *
 * @param  list<string>  $scopes
 * @param  array<string, mixed>  $overrides
 * @return array{0: ApiClient, 1: string}
 */
function keyed(array $scopes = ['catalogue:read'], array $overrides = []): array
{
    return ApiClientFactory::withKey($scopes, $overrides);
}

/** @return array<string, string> */
function bearer(string $key): array
{
    return ['Authorization' => 'Bearer '.$key];
}
