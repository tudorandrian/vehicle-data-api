<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use VehicleData\Core\Auth\ApiKey;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Models\ApiClient;

/**
 * @extends Factory<ApiClient>
 */
final class ApiClientFactory extends Factory
{
    protected $model = ApiClient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = ApiKey::generate();

        return [
            'name' => fake()->word(),
            'owner' => fake()->name(),
            'key_prefix' => ApiKey::prefix($key),
            'key_hash' => ApiKey::hash($key),
            'allowed_origins' => [],
            'rate_per_minute' => 60,
            'daily_quota' => 10000,
            'expires_at' => null,
            'disabled_at' => null,
        ];
    }

    /**
     * Creates a client with the given scopes and returns [client, plaintextKey].
     *
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $overrides
     * @return array{0: ApiClient, 1: string}
     */
    public static function withKey(array $scopes = [ResolvedClient::SCOPE_CATALOGUE], array $overrides = []): array
    {
        $key = ApiKey::generate();
        $client = ApiClient::factory()->create([
            'key_prefix' => ApiKey::prefix($key),
            'key_hash' => ApiKey::hash($key),
        ] + $overrides);
        foreach ($scopes as $scope) {
            $client->scopes()->create(['scope' => $scope]);
        }

        return [$client, $key];
    }
}
