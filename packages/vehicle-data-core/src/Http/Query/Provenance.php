<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use VehicleData\Core\Models\RecordSource;
use VehicleData\Core\Models\Source;

final class Provenance
{
    /**
     * `X-Data-Attribution` value for a set of records (one list page): the English attribution of
     * every source that contributed to at least one of them, in source order, `; `-separated and
     * ASCII-only (header-safe). Null when no record has provenance (for example an empty page).
     *
     * @param  list<Model>  $records
     */
    public static function attributionHeader(array $records): ?string
    {
        if ($records === []) {
            return null;
        }
        $sourceIds = RecordSource::query()
            ->where('record_type', $records[0]->getMorphClass())
            ->whereIn('record_id', array_map(static fn (Model $m) => $m->getKey(), $records))
            ->select('source_id')->distinct();

        return self::join(Source::query()->whereIn('id', $sourceIds)->orderBy('id')->pluck('attribution')->all());
    }

    /** `X-Data-Attribution` value for every source that has contributed a record of the given morph type (whole-resource snapshots). */
    public static function attributionHeaderForType(string $morphType): ?string
    {
        $sourceIds = RecordSource::query()->where('record_type', $morphType)->select('source_id')->distinct();

        return self::join(Source::query()->whereIn('id', $sourceIds)->orderBy('id')->pluck('attribution')->all());
    }

    /** @param  array<int, mixed>  $attributions */
    private static function join(array $attributions): ?string
    {
        $clean = array_values(array_unique(array_filter(array_map(static fn ($a): string => trim(Str::ascii((string) $a)), $attributions))));

        return $clean === [] ? null : implode('; ', $clean);
    }

    /**
     * A record can carry more than one vd_record_sources row per source (e.g. a make
     * matched by both provenance and slug across separate imports): report the newest
     * one. Ordering by retrieved_at DESC within each source_id, before the per-source
     * dedup below keeps only the first row seen, makes that deterministic - it no longer
     * depends on index/insertion order.
     *
     * @return list<array{key:string,licence:string,attribution:string,retrieved_at:string}>
     */
    public static function for(Model $record): array
    {
        $rows = RecordSource::query()->with('source')
            ->where('record_type', $record->getMorphClass())->where('record_id', $record->getKey())
            ->orderBy('source_id')->orderByDesc('retrieved_at')->get();

        $seen = [];
        $out = [];
        foreach ($rows as $rs) {
            if ($rs->source === null || isset($seen[$rs->source->key])) {
                continue;
            }
            $seen[$rs->source->key] = true;
            $out[] = [
                'key' => $rs->source->key, 'licence' => $rs->source->licence_id,
                'attribution' => $rs->source->attribution, 'retrieved_at' => $rs->retrieved_at->toIso8601String(),
            ];
        }

        return $out;
    }
}
