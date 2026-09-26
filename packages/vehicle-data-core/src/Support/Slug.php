<?php

declare(strict_types=1);

namespace VehicleData\Core\Support;

use Illuminate\Support\Str;

final class Slug
{
    public static function make(string $name): string
    {
        return Str::slug(Str::ascii(trim($name)), '-');
    }
}
