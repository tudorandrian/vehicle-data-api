<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\PublicId;
use VehicleData\Core\Support\Slug;

/**
 * Finds the make or model a source row refers to (ADR 0007, spec H4):
 *  1. by provenance - a vd_record_sources row for this source whose source_ref is the RAW value as published;
 *  2. by the slug of the normalised name (this is how "VW" and "VOLKSWAGEN" converge);
 *  3. otherwise create.
 * Then, if the normalised name differs from the stored one, renames the record: new name and slug,
 * the old slug kept in vd_slug_aliases, an alias equal to the new slug removed. A new slug that is
 * already another live record's slug rejects the row (slug_collision) - no silent merges.
 *
 * rename() and assertSlugFree() are also reused directly by ManufacturerWriter: manufacturers
 * follow the same slug/alias rules, they are just resolved by wikidata_qid rather than by
 * provenance/slug fallback.
 */
final class CatalogueIdentity
{
    /** vd_record_sources.source_ref is varchar(120); never truncate it silently. */
    private const MAX_REF_LENGTH = 120;

    public static function make(Source $source, string $raw, string $name, string $kind, \DateTimeInterface $retrievedAt): Make
    {
        self::assertRefLength($raw);
        $slug = Slug::make($name);
        // The provenance lookup and the slug fallback are both kind-scoped: a make of another kind with
        // the same raw value or slug is a different catalogue entry, not this one.
        $make = self::byProvenance(Make::query()->where('kind', $kind), $source, $raw)
            ?? Make::query()->where('kind', $kind)->where('slug', $slug)->first();
        if ($make === null) {
            // The live-slug half of this check is deliberately NOT kind-scoped: vd_makes.slug is unique
            // across every kind, so a same-slug make of another kind must reject this row (slug_collision)
            // rather than hit the DB's unique constraint and abort the whole run.
            self::assertSlugFree(Make::class, (new Make)->getMorphClass(), $slug, null);
            // The minted key deliberately omits $kind: every writer that reaches here passes 'car'
            // today, so "make|{$source->key}|{$raw}" cannot collide across kinds. A writer that
            // starts importing another kind under the same source key and the same raw value would
            // need $kind folded into this key, or two such makes would mint the same public_id.
            $make = Make::query()->create(['slug' => $slug, 'name' => $name, 'kind' => $kind, 'public_id' => PublicId::for("make|{$source->key}|{$raw}")]);
        } else {
            self::rename(Make::class, $make, $name, $slug);
        }
        self::provenance($make, $source, $raw, $retrievedAt);

        return $make;
    }

    public static function model(Source $source, Make $make, string $raw, string $name, \DateTimeInterface $retrievedAt): VehicleModel
    {
        // The ref is keyed by the make's immutable public_id, not its slug: a make rename must not orphan its models.
        $ref = $make->public_id.'|'.$raw;
        self::assertRefLength($ref);
        $slug = Slug::make($make->name.' '.$name);
        $model = self::byProvenance(VehicleModel::query()->where('make_id', $make->id), $source, $ref)
            ?? VehicleModel::query()->where('make_id', $make->id)->where('slug', $slug)->first();
        if ($model === null) {
            self::assertSlugFree(VehicleModel::class, (new VehicleModel)->getMorphClass(), $slug, null);
            $model = VehicleModel::query()->create(['slug' => $slug, 'make_id' => $make->id, 'name' => $name, 'public_id' => PublicId::for("model|{$source->key}|{$ref}")]);
        } else {
            self::rename(VehicleModel::class, $model, $name, $slug);
        }
        self::provenance($model, $source, $ref, $retrievedAt);

        return $model;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel|null
     */
    private static function byProvenance(Builder $query, Source $source, string $ref): ?Model
    {
        $ids = RecordSource::query()->where('record_type', $query->getModel()->getMorphClass())
            ->where('source_id', $source->id)->where('source_ref', $ref)->pluck('record_id');
        if ($ids->isEmpty()) {
            return null;
        }

        return (clone $query)->whereIn($query->getModel()->getKeyName(), $ids)->first();
    }

    /**
     * Shared by make/model resolution above and by ManufacturerWriter (manufacturers are
     * catalogue records too, ADR 0007, and must not bypass the rename/alias machinery):
     * when the normalised name differs from the stored one, writes the new name and slug,
     * keeps the old slug in vd_slug_aliases, and drops an alias equal to the new slug. A
     * new slug already live or aliased elsewhere rejects the row (slug_collision) - no
     * silent merges or stolen slugs.
     *
     * @param  class-string<Make>|class-string<VehicleModel>|class-string<Manufacturer>  $modelClass
     */
    public static function rename(string $modelClass, Model $record, string $name, string $slug): void
    {
        /** @var Make|VehicleModel|Manufacturer $record */
        if ($record->name === $name && $record->slug === $slug) {
            return;
        }
        if ($record->slug !== $slug) {
            self::assertSlugFree($modelClass, $record->getMorphClass(), $slug, $record->getKey());
            // vd_slug_aliases has a composite unique on (record_type, slug): reject rather than
            // silently steal the old slug from another record of the SAME type that already
            // holds it as an alias. A different type (e.g. a manufacturer and a make both once
            // called "renault") is not a collision - the alias namespace is per record type.
            if (SlugAlias::query()->where('record_type', $record->getMorphClass())->where('slug', $record->slug)->exists()) {
                throw new RowRejected('slug_collision');
            }
            SlugAlias::query()->create(['record_type' => $record->getMorphClass(), 'record_id' => $record->getKey(), 'slug' => $record->slug]);
            SlugAlias::query()->where('record_type', $record->getMorphClass())->where('record_id', $record->getKey())->where('slug', $slug)->delete();
        }
        $record->forceFill(['name' => $name, 'slug' => $slug])->save();
    }

    /** @param  class-string<Make>|class-string<VehicleModel>|class-string<Manufacturer>  $modelClass */
    public static function assertSlugFree(string $modelClass, string $type, string $slug, int|string|null $exceptId): void
    {
        $live = $modelClass::query()->where('slug', $slug)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        // The alias namespace is per record type (vd_slug_aliases has a composite unique on
        // (record_type, slug)): manufacturer slugs and make slugs collide constantly, so only an
        // alias of the SAME type is a collision, except the alias that already belongs to this
        // very record.
        $aliased = SlugAlias::query()->where('record_type', $type)->where('slug', $slug)
            ->when($exceptId !== null, fn ($q) => $q->where('record_id', '!=', $exceptId))
            ->exists();
        if ($live || $aliased) {
            throw new RowRejected('slug_collision');
        }
    }

    /** source_ref is varchar(120): reject rather than silently truncate a ref that would overflow it. */
    private static function assertRefLength(string $ref): void
    {
        if (mb_strlen($ref) > self::MAX_REF_LENGTH) {
            throw new RowRejected('ref_too_long');
        }
    }

    /**
     * updateOrCreate, not firstOrCreate: a make/model re-imported from the same source_ref
     * must advance retrieved_at on every run, exactly like ImportPipeline's own provenance()
     * write for the record itself - otherwise this identity-resolution row would freeze at
     * whatever timestamp the very first import happened to write.
     *
     * $retrievedAt is the run's single captured instant (ImportPipeline::run()), not now():
     * a make/model is typically referenced by hundreds of rows in one run, and passing now()
     * made this row dirty on every single one of them (a different value each time), firing an
     * UPDATE per row instead of Eloquent skipping the write after the first once the attributes
     * stop changing. retrieved_at still advances once per run, just not once per row.
     */
    private static function provenance(Model $record, Source $source, string $ref, \DateTimeInterface $retrievedAt): void
    {
        RecordSource::query()->updateOrCreate(
            ['record_type' => $record->getMorphClass(), 'record_id' => $record->getKey(), 'source_id' => $source->id, 'source_ref' => $ref],
            ['retrieved_at' => $retrievedAt, 'checksum' => hash('sha256', $ref)],
        );
    }
}
