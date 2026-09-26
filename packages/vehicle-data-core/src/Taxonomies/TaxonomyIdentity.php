<?php

declare(strict_types=1);

namespace VehicleData\Core\Taxonomies;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyAlias;
use VehicleData\Core\Models\TaxonomyTerm;
use VehicleData\Core\Models\TaxonomyTermAlias;

/**
 * The only supported write path for changing a published taxonomy name or term code.
 * It retains the retired key as an alias and rejects reuse by a different identity.
 */
final class TaxonomyIdentity
{
    public static function rename(Taxonomy $taxonomy, string $name): Taxonomy
    {
        if (preg_match('/^[a-z_]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Taxonomy names must contain only lowercase letters and underscores.');
        }
        if ($taxonomy->name === $name) {
            return $taxonomy;
        }

        return DB::transaction(function () use ($taxonomy, $name): Taxonomy {
            $current = Taxonomy::query()->lockForUpdate()->findOrFail($taxonomy->id);
            self::assertNameAvailable($current, $name);
            TaxonomyAlias::query()->firstOrCreate(['name' => $current->name], ['taxonomy_id' => $current->id]);
            TaxonomyAlias::query()->where('taxonomy_id', $current->id)->where('name', $name)->delete();
            $current->update(['name' => $name]);

            return $current->refresh();
        });
    }

    public static function renameTerm(TaxonomyTerm $term, string $code): TaxonomyTerm
    {
        if (preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
            throw new InvalidArgumentException('Taxonomy term codes must contain only lowercase letters, digits and underscores.');
        }
        if ($term->code === $code) {
            return $term;
        }

        return DB::transaction(function () use ($term, $code): TaxonomyTerm {
            $current = TaxonomyTerm::query()->lockForUpdate()->findOrFail($term->id);
            self::assertCodeAvailable($current, $code);
            TaxonomyTermAlias::query()->firstOrCreate(
                ['taxonomy_id' => $current->taxonomy_id, 'code' => $current->code],
                ['term_id' => $current->id],
            );
            TaxonomyTermAlias::query()->where('term_id', $current->id)->where('code', $code)->delete();
            self::replaceCatalogueReferences($current, $code);
            TaxonomyTerm::query()->where('taxonomy_id', $current->taxonomy_id)->where('parent_code', $current->code)->update(['parent_code' => $code]);
            $current->update(['code' => $code]);

            return $current->refresh();
        });
    }

    private static function assertNameAvailable(Taxonomy $taxonomy, string $name): void
    {
        $owner = Taxonomy::query()->where('name', $name)->value('id');
        $aliasOwner = TaxonomyAlias::query()->where('name', $name)->value('taxonomy_id');
        if (($owner !== null && (int) $owner !== $taxonomy->id) || ($aliasOwner !== null && (int) $aliasOwner !== $taxonomy->id)) {
            throw new LogicException("Taxonomy name [$name] is reserved by another taxonomy.");
        }
    }

    private static function assertCodeAvailable(TaxonomyTerm $term, string $code): void
    {
        $owner = TaxonomyTerm::query()->where('taxonomy_id', $term->taxonomy_id)->where('code', $code)->value('id');
        $aliasOwner = TaxonomyTermAlias::query()->where('taxonomy_id', $term->taxonomy_id)->where('code', $code)->value('term_id');
        if (($owner !== null && (int) $owner !== $term->id) || ($aliasOwner !== null && (int) $aliasOwner !== $term->id)) {
            throw new LogicException("Taxonomy term code [$code] is reserved by another term.");
        }
    }

    private static function replaceCatalogueReferences(TaxonomyTerm $term, string $code): void
    {
        $taxonomy = Taxonomy::query()->findOrFail($term->taxonomy_id);
        $column = match ($taxonomy->name) {
            'fuel' => 'fuel_code',
            'eu_category' => 'eu_category_code',
            'euro_norm' => 'euro_norm_code',
            default => null,
        };
        if ($column !== null) {
            DB::table('vd_variants')->where($column, $term->code)->update([$column => $code]);
        }
    }
}
