<?php

declare(strict_types=1);

namespace VehicleData\Core\Taxonomies;

use Illuminate\Support\Facades\DB;

/**
 * Resolves a current or retired term code to the current code for API filters.
 *
 * @see ADR 0009
 */
final class TaxonomyCodeResolver
{
    /**
     * @param  list<string>  $taxonomyNames
     * @return array<string, array<string, string>> taxonomy name => accepted code => current code
     */
    public static function canonicalCodes(array $taxonomyNames): array
    {
        $names = array_values(array_unique($taxonomyNames));
        $codes = array_fill_keys($names, []);
        if ($names === []) {
            return $codes;
        }

        DB::table('vd_taxonomy_terms')
            ->join('vd_taxonomies', 'vd_taxonomies.id', '=', 'vd_taxonomy_terms.taxonomy_id')
            ->whereIn('vd_taxonomies.name', $names)
            ->get(['vd_taxonomies.name as taxonomy_name', 'vd_taxonomy_terms.code'])
            ->each(function (object $term) use (&$codes): void {
                $codes[$term->taxonomy_name][$term->code] = $term->code;
            });

        DB::table('vd_taxonomy_term_aliases')
            ->join('vd_taxonomies', 'vd_taxonomies.id', '=', 'vd_taxonomy_term_aliases.taxonomy_id')
            ->join('vd_taxonomy_terms', 'vd_taxonomy_terms.id', '=', 'vd_taxonomy_term_aliases.term_id')
            ->whereIn('vd_taxonomies.name', $names)
            ->get(['vd_taxonomies.name as taxonomy_name', 'vd_taxonomy_term_aliases.code as alias_code', 'vd_taxonomy_terms.code'])
            ->each(function (object $alias) use (&$codes): void {
                $codes[$alias->taxonomy_name][$alias->alias_code] = $alias->code;
            });

        return $codes;
    }
}
