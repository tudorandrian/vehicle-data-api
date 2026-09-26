<?php

declare(strict_types=1);

namespace VehicleData\Core\Support;

final class Power
{
    public static function hp(?int $kw): ?int
    {
        return $kw === null ? null : (int) round($kw * 1.35962);
    }
}
