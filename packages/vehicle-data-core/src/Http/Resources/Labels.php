<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Resources;

final class Labels
{
    /**
     * Resolves a taxonomy code to its {code,label} pair from the bilingual
     * translation files (the source of truth for `vd_taxonomy_labels`), so
     * a variant list never pays for a per-row label query.
     *
     * @return array{code:string,label:string}|null
     */
    public static function term(string $taxonomy, ?string $code, string $lang): ?array
    {
        if ($code === null) {
            return null;
        }
        $key = "core::taxonomies.{$taxonomy}.{$code}";
        $label = trans($key, [], $lang);

        return ['code' => $code, 'label' => $label === $key ? $code : $label];
    }
}
