<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

final class Fields
{
    /**
     * @param  array<string,mixed>  $values
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    public static function required(array $values, array $keys): array
    {
        foreach ($keys as $k) {
            $values[$k] = $values[$k] ?? null;
        }

        return $values;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public static function optional(array $values): array
    {
        return array_filter($values, static fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<string>  $requested
     * @param  list<string>  $always
     * @return array<string,mixed>
     */
    public static function sparse(array $data, array $requested, array $always): array
    {
        if ($requested === []) {
            return $data;
        }
        $keep = array_flip(array_merge($always, $requested));

        return array_intersect_key($data, $keep);
    }
}
