<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Writers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use VehicleData\Core\Contracts\RecordWriter;
use VehicleData\Core\Contracts\RunAware;
use VehicleData\Core\Importers\CatalogueIdentity;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Support\PublicId;

/**
 * write() only stores each manufacturer's own attributes, including its parent's QID
 * (parent_qid) - it never looks up the parent row here, so it does not matter whether the
 * parent or the child is written first within a run. finish() (RunAware, called once per
 * successful run by ImportPipeline) then resolves every manufacturer's parent_slug from
 * parent_qid in a single UPDATE...JOIN, after every row in the run has been written.
 *
 * This writer is bound as part of the ImportPipeline singleton (CoreServiceProvider), so one
 * instance can live across several runs in the same process (a queue worker, or a test suite
 * that does not reboot the container between cases). Nothing on this class is memoized across
 * write() calls for that reason: an in-memory cache mutated mid-run would go stale the moment
 * that run's transaction rolls back (aborted/failed import), and there would be no later call
 * - finish() only runs for a successful run - to invalidate it before the next run reads it.
 */
final class ManufacturerWriter implements RecordWriter, RunAware
{
    public function type(): string
    {
        return 'manufacturer';
    }

    /**
     * Resolves the manufacturer by its immutable wikidata_qid, then writes slug/name through
     * CatalogueIdentity::rename() rather than folding them into an updateOrCreate() - a
     * manufacturer is a catalogue record like a make or model (ADR 0007) and a slug change
     * must go through the same alias bookkeeping and live/alias collision check, or a retired
     * slug 404s instead of 301ing and stays free for a different manufacturer to claim.
     *
     * @return list<Model>
     */
    public function write(DomainRow $row, Source $source, \DateTimeInterface $retrievedAt): array
    {
        $a = $row->attributes;
        $attributes = [
            'country_code' => $a['country_code'] ?? null, 'founded_year' => $a['founded_year'] ?? null,
            'website' => $a['website'] ?? null, 'logo_commons_file' => $a['logo_commons_file'] ?? null, 'logo_licence' => $a['logo_licence'] ?? null,
            'parent_qid' => $a['parent_qid'] ?? null,
        ];

        $manufacturer = Manufacturer::query()->where('wikidata_qid', $a['wikidata_qid'])->first();
        if ($manufacturer === null) {
            CatalogueIdentity::assertSlugFree(Manufacturer::class, (new Manufacturer)->getMorphClass(), $a['slug'], null);
            $manufacturer = Manufacturer::query()->create($attributes + [
                'wikidata_qid' => $a['wikidata_qid'], 'slug' => $a['slug'], 'name' => $a['name'],
                'public_id' => PublicId::for('manufacturer|wikidata|'.$a['wikidata_qid']),
            ]);
        } else {
            CatalogueIdentity::rename(Manufacturer::class, $manufacturer, $a['name'], $a['slug']);
            $manufacturer->forceFill($attributes)->save();
        }

        $touched = [$manufacturer];
        $makeSlugs = (array) ($a['make_slugs'] ?? []);
        if ($makeSlugs !== []) {
            foreach (Make::all() as $make) {
                $matches = in_array($make->slug, $makeSlugs, true)
                    || array_any($makeSlugs, static fn (string $labelSlug): bool => str_starts_with($labelSlug, $make->slug.'-'));
                if (! $matches) {
                    continue;
                }
                if ($make->manufacturer_id !== $manufacturer->id) {
                    $make->forceFill(['manufacturer_id' => $manufacturer->id])->save();
                }
                $touched[] = $make;
            }
        }

        return $touched;
    }

    public function finish(Source $source): void
    {
        DB::statement(
            'UPDATE vd_manufacturers c JOIN vd_manufacturers p ON p.wikidata_qid = c.parent_qid '.
            'SET c.parent_slug = p.slug WHERE c.parent_qid IS NOT NULL AND c.parent_qid <> c.wikidata_qid'
        );
    }
}
