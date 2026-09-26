<?php

declare(strict_types=1);

namespace VehicleData\Core\Contracts;

use VehicleData\Core\Auth\ResolvedClient;

interface ClientResolver
{
    /** Return null for unknown, disabled or expired keys. Must be constant-time with respect to the secret part. */
    public function resolve(string $bearer): ?ResolvedClient;
}
