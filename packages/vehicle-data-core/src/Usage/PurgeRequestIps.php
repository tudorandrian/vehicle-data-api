<?php

declare(strict_types=1);

namespace VehicleData\Core\Usage;

use VehicleData\Core\Models\ApiRequest;

/**
 * Nulls (never deletes the row - the aggregate counts must survive) the
 * `ip` column on `vd_api_requests` rows older than
 * `core.request_ip_retention_days`.
 */
final class PurgeRequestIps
{
    public static function run(): int
    {
        return ApiRequest::query()
            ->whereNotNull('ip')
            ->where('created_at', '<', now()->subDays((int) config('core.request_ip_retention_days', 30)))
            ->update(['ip' => null]);
    }
}
