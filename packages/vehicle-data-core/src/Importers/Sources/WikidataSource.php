<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Sources;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use VehicleData\Core\Contracts\DataSource;
use VehicleData\Core\Importers\CommonsLicence;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Licence;
use VehicleData\Core\Importers\NameNormaliser;
use VehicleData\Core\Importers\RawRow;
use VehicleData\Core\Importers\Reject;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\Manufacturer;
use VehicleData\Core\Models\SlugAlias;
use VehicleData\Core\Support\Slug;

/**
 * Wikidata - automobile manufacturers (wd:Q786820), for a fixed list of make labels.
 *
 * A make label can resolve to more than one Wikidata item (disambiguation, or a group vs. a
 * brand sharing a label), and two distinct items can normalise to the same slug (e.g. two
 * "Renault" entities). fetch() decides - deterministically, from the sorted-lowest QID among
 * the colliding items, never from fetch/processing order - which one keeps the slug; map()
 * rejects the other with `duplicate_slug` rather than silently overwriting the winner
 * or aborting the whole import. This also keeps parent resolution deterministic: whichever QID
 * a parent reference points to either wins its slug or does not, independent of how the
 * SPARQL results or a regenerated fixture happen to be ordered.
 */
final class WikidataSource implements DataSource
{
    public const SPARQL = 'https://query.wikidata.org/sparql';

    public const COMMONS = 'https://commons.wikimedia.org/w/api.php';

    private const UA = 'vehicle-data-api/1.0 (open-data importer)';

    /** @var array<string,?string> file name -> LicenseShortName, from the fixture's "commons" map. */
    private array $commons = [];

    /** @var array<string,string> slug -> the QID that deterministically wins it in this run. */
    private array $slugWinners = [];

    public function key(): string
    {
        return 'wikidata';
    }

    public function name(): string
    {
        return 'Wikidata - automobile manufacturers';
    }

    public function url(): string
    {
        return 'https://www.wikidata.org/';
    }

    public function licence(): Licence
    {
        return Licence::cc0();
    }

    public function fetch(ImportOptions $options): iterable
    {
        // Reset per-run state: this source is registered as a singleton, so a fresh run must
        // never see slug winners computed for a previous run's (possibly quite different) set
        // of fetched items.
        $this->slugWinners = [];
        if ($options->file !== null) {
            $fx = json_decode(File::get($options->file), true);
            $this->commons = (array) ($fx['commons'] ?? []);
            $bindings = (array) ($fx['sparql']['results']['bindings'] ?? []);
        } else {
            $labels = Make::query()->where('kind', 'car')->orderBy('name')->pluck('name')->all();
            $bindings = [];
            foreach (array_chunk($labels, 40) as $chunk) {
                $values = implode(' ', array_map(fn (string $l): string => json_encode($l).'@en', $chunk));
                $query = 'SELECT ?item ?itemLabel ?label ?countryCode ?inception ?parent ?website ?logo WHERE { '
                    ."VALUES ?label { {$values} } ?item rdfs:label ?label ; wdt:P31/wdt:P279* wd:Q786820 . "
                    .'OPTIONAL { ?item wdt:P17 ?country . ?country wdt:P297 ?countryCode } OPTIONAL { ?item wdt:P571 ?inception } '
                    .'OPTIONAL { ?item wdt:P749 ?parent } OPTIONAL { ?item wdt:P856 ?website } OPTIONAL { ?item wdt:P154 ?logo } '
                    .'SERVICE wikibase:label { bd:serviceParam wikibase:language "en" . } } ORDER BY ?item';
                $res = Http::withUserAgent(self::UA)->accept('application/sparql-results+json')->timeout(120)->retry(3, 3000)->get(self::SPARQL, ['query' => $query])->throw();
                $bindings = array_merge($bindings, (array) ($res->json('results.bindings') ?? []));
                usleep(500_000);
            }
        }

        $byItem = [];
        foreach ($bindings as $b) {
            $qid = basename((string) $b['item']['value']);
            $byItem[$qid] ??= ['bindings' => [], 'labels' => []];
            $byItem[$qid]['bindings'][] = $b;
            $byItem[$qid]['labels'][] = (string) ($b['label']['value'] ?? $b['itemLabel']['value'] ?? '');
        }
        // Every QID actually fetched in this run, used to prefer an already-known parent
        // (see mergeGroup()) over an arbitrary one when an item has more than one P749 value.
        $itemQids = array_keys($byItem);

        // Decide, once and deterministically, which QID keeps a slug that more than one item
        // in this run normalises to: the sorted-lowest QID always wins, regardless of the
        // order fetch() happens to process items in (see the class docblock).
        $bySlug = [];
        foreach ($byItem as $qid => $group) {
            $name = NameNormaliser::make((string) ($group['bindings'][0]['itemLabel']['value'] ?? ''));
            if ($name instanceof Reject) {
                continue;
            }
            $bySlug[Slug::make($name)][] = $qid;
        }
        foreach ($bySlug as $slug => $qids) {
            sort($qids);
            $this->slugWinners[$slug] = $qids[0];
        }

        $n = 0;
        foreach ($byItem as $qid => $group) {
            if ($options->limit !== null && $n++ >= $options->limit) {
                return;
            }
            yield new RawRow(['qid' => $qid] + $this->mergeGroup($group, $itemQids, $options), $qid);
        }
    }

    /**
     * A Wikidata item's multi-valued properties (e.g. Dacia/Q27460 has two P749 "parent"
     * values, Q6686 and Q1477864) produce more than one binding per item, in whatever order
     * the endpoint or the fixture happens to list them. Picking bindings[0] made every
     * downstream field - parent, country, inception, website, logo - order-dependent: a
     * regenerated fixture or a re-run could silently pick a different parent (breaking
     * the importer test's Dacia → Renault parent assertion) or a different country/inception/website/logo. This
     * merges all of an item's bindings into one deterministic row, independent of input order:
     * country/inception/website take the lexicographically smallest non-null value (inception
     * is an ISO 8601 timestamp, so that is also the earliest date); the logo is the
     * alphabetically-first file whose Commons licence is free, falling back to the
     * alphabetically-first file if none is; the parent is the sorted-lowest candidate that is
     * itself among the QIDs fetched in this run, falling back to the sorted-lowest candidate
     * overall.
     *
     * @param  array{bindings: list<array<string,mixed>>, labels: list<string>}  $group
     * @param  list<string>  $itemQids
     * @return array<string,mixed>
     */
    private function mergeGroup(array $group, array $itemQids, ImportOptions $options): array
    {
        $countryCodes = $this->distinctNonNull($group['bindings'], 'countryCode');
        $inceptions = $this->distinctNonNull($group['bindings'], 'inception');
        $websites = $this->distinctNonNull($group['bindings'], 'website');

        $parents = array_values(array_unique(array_map(
            static fn (string $uri): string => basename($uri),
            $this->distinctNonNull($group['bindings'], 'parent'),
        )));
        sort($parents);
        $knownParents = array_values(array_intersect($parents, $itemQids));
        $parentQid = $knownParents[0] ?? ($parents[0] ?? null);

        sort($countryCodes);
        sort($inceptions);
        sort($websites);

        $logoFiles = array_values(array_unique(array_map(
            static fn (string $uri): string => rawurldecode(substr($uri, strlen('http://commons.wikimedia.org/wiki/Special:FilePath/'))),
            $this->distinctNonNull($group['bindings'], 'logo'),
        )));
        sort($logoFiles);
        [$logoFile, $logoLicence] = $this->pickLogo($logoFiles, $options);

        return [
            'name' => (string) ($group['bindings'][0]['itemLabel']['value'] ?? ''),
            'labels' => array_values(array_unique($group['labels'])),
            'country_code' => $countryCodes[0] ?? null,
            'inception' => $inceptions[0] ?? null,
            'parent_qid' => $parentQid,
            'website' => $websites[0] ?? null,
            'logo_file' => $logoFile,
            'logo_licence' => $logoLicence,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $bindings
     * @return list<string>
     */
    private function distinctNonNull(array $bindings, string $key): array
    {
        $values = [];
        foreach ($bindings as $b) {
            if (isset($b[$key]['value']) && $b[$key]['value'] !== '') {
                $values[] = (string) $b[$key]['value'];
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<string>  $files  sorted ascending
     * @return array{0: ?string, 1: ?string}
     */
    private function pickLogo(array $files, ImportOptions $options): array
    {
        $first = null;
        foreach ($files as $file) {
            $licence = $this->licenceFor($file, $options);
            $first ??= [$file, $licence];
            if (CommonsLicence::isFree($licence)) {
                return [$file, $licence];
            }
        }

        return $first ?? [null, null];
    }

    private function licenceFor(string $file, ImportOptions $options): ?string
    {
        if ($options->file !== null) {
            return $this->commons[$file] ?? null;
        }
        $res = Http::withUserAgent(self::UA)->timeout(60)->retry(3, 2000)->get(self::COMMONS, [
            'action' => 'query', 'titles' => 'File:'.$file, 'prop' => 'imageinfo',
            'iiprop' => 'extmetadata', 'iiextmetadatafilter' => 'LicenseShortName', 'format' => 'json',
        ])->throw();
        $page = array_values((array) $res->json('query.pages'))[0] ?? [];

        return $page['imageinfo'][0]['extmetadata']['LicenseShortName']['value'] ?? null;
    }

    public function map(RawRow $row): ?DomainRow
    {
        $d = $row->data;
        $qid = (string) $d['qid'];
        $name = NameNormaliser::make((string) $d['name']);
        if ($name instanceof Reject) {
            return $row->reject('name:'.$name->rule);
        }
        $slug = Slug::make($name);

        // A slug claimed by another QID - either the deterministic winner among this
        // run's own items (see fetch()), or a manufacturer already committed by an earlier run
        // - is a genuine collision (e.g. two distinct "Renault" items). Reject the row rather
        // than silently merging two manufacturers or letting the whole import abort on a
        // unique-key violation at write time.
        $runnerUpOf = $this->slugWinners[$slug] ?? $qid;
        if ($runnerUpOf !== $qid) {
            return $row->reject('duplicate_slug');
        }
        $claimedBy = Manufacturer::query()->where('slug', $slug)->value('wikidata_qid');
        if ($claimedBy !== null && $claimedBy !== $qid) {
            return $row->reject('duplicate_slug');
        }
        // A retired manufacturer slug is reserved too (vd_slug_aliases), same as for makes/models
        // (CatalogueIdentity::assertSlugFree): otherwise a manufacturer that renamed away from this
        // slug could have it handed to an unrelated company later. Reusing its OWN retired slug is
        // fine - ManufacturerWriter::write() -> CatalogueIdentity::rename() re-checks this properly
        // (with the record's own id excepted) at write time.
        $aliasOwnerId = SlugAlias::query()->where('record_type', (new Manufacturer)->getMorphClass())->where('slug', $slug)->value('record_id');
        if ($aliasOwnerId !== null && Manufacturer::query()->whereKey($aliasOwnerId)->value('wikidata_qid') !== $qid) {
            return $row->reject('duplicate_slug');
        }

        $free = CommonsLicence::isFree($d['logo_licence']);

        return new DomainRow('manufacturer', [
            'wikidata_qid' => $qid,
            'name' => $name,
            'slug' => $slug,
            'country_code' => $d['country_code'] !== null ? strtoupper((string) $d['country_code']) : null,
            'founded_year' => $d['inception'] !== null ? (int) substr((string) $d['inception'], 0, 4) : null,
            'parent_qid' => $d['parent_qid'],
            'website' => $d['website'],
            'logo_commons_file' => $free ? $d['logo_file'] : null,
            'logo_licence' => $free ? $d['logo_licence'] : null,
            'make_slugs' => array_map(fn (string $l): string => Slug::make($l), $d['labels']),
        ], $row->ref);
    }

    public function naturalKey(DomainRow $row): string
    {
        return 'wikidata|'.$row->attributes['wikidata_qid'];
    }
}
