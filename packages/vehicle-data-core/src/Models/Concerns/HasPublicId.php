<?php

declare(strict_types=1);

namespace VehicleData\Core\Models\Concerns;

use VehicleData\Core\Support\PublicIdFormat;

/** Fills `public_id` once, on insert; the value is never touched again (ADR 0007). */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->public_id)) {
                $model->public_id = PublicIdFormat::generate();
            }
        });
    }
}
