<?php

declare(strict_types=1);

namespace VehicleData\Core\Auth;

final readonly class ResolvedClient
{
    public const SCOPE_CATALOGUE = 'catalogue:read';

    public const SCOPE_VIN = 'vin:decode';

    public const SCOPE_SNAPSHOT = 'snapshot:read';

    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $allowedOrigins
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $keyPrefix,
        public array $scopes,
        public array $allowedOrigins,
        public int $ratePerMinute,
        public int $dailyQuota,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
