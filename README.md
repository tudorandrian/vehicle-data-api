# vehicle-data-api

Read-only vehicle catalogue API built with Laravel 13 on openly licensed public data - makes, models, variants, manufacturers and bilingual (ro/en) taxonomies, with per-record provenance and an OpenAPI contract enforced in CI.

A portfolio project by Tudor Andrian, built with AI assistance (Claude Code) and reviewed and released by the author.

> [!IMPORTANT]
> **v1 is a reference implementation with sample data, not a finished data product.** The code, the contract and the tests are complete and run in CI, but a default install serves a small committed sample of each source (at most 200 rows per file), and there is no hosted service. The data is not a complete vehicle catalogue, not a source of national statistics and not a register of homologated configurations. For example, Dacia's `ro_fleet.count` of 10415 in the calls below is the sum of 13 rows from a single county (Alba) in the sample, not the number of Dacias registered in Romania. Read [what the sample covers](docs/data-sources.md#what-the-sample-covers) and the [known limitations](docs/known-limitations.md) before relying on any value; what v2 will change is in the [roadmap](docs/roadmap.md).

[![ci](https://github.com/tudorandrian/vehicle-data-api/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tudorandrian/vehicle-data-api/actions/workflows/ci.yml)
[![licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

The interactive API reference is served by the app itself at `/docs`: a self-hosted Scalar page generated from the OpenAPI document, with a search box, every route grouped by tag (system, taxonomies, catalogue, vin, snapshots), request and response schemas with examples, and a "Try it" panel that sends real requests with your bearer key. It loads nothing from other origins.

## Try it in six commands

You need Git and Docker (with Compose). Everything else, PHP 8.4, MariaDB 10.5 (or 10.11, chosen with `VD_MARIADB_TAG` in `.env`) and Node for the docs bundle, runs in containers.

Compose publishes its development ports on `127.0.0.1` only. Remote access requires an intentional local override; do not expose the development database or mail services with their default credentials.

```bash
git clone https://github.com/tudorandrian/vehicle-data-api.git && cd vehicle-data-api && cp .env.example .env
```

If you already have this stack running from another checkout, edit the new `.env` now and set a different `COMPOSE_PROJECT_NAME` (and different `VD_*` ports): Compose addresses containers by project name, so otherwise the second checkout recreates the first one's containers in place, with no error to say so. With a different `VD_HTTP_PORT`, use that port instead of `8087` in the `curl` call below and in the `/docs` URL.

```bash
docker compose up -d --build
docker compose exec -T app sh -c "composer install && php artisan key:generate && php artisan migrate --seed --force"
docker run --rm -v "$PWD":/app -w /app node:24 sh -c "npm ci && npm run postinstall"
docker compose exec -T app php artisan vehicle:client create --name=me --owner=me --scopes=catalogue:read,vin:decode,snapshot:read
curl -s -H "Authorization: Bearer <key printed by the previous command>" "http://localhost:8087/v1/makes/dacia/models?per_page=3"
```

Then open http://localhost:8087/docs. The fourth command copies the Scalar bundle into `public/vendor/scalar/` (`.npmrc` disables install scripts, so `postinstall` is run by name); with Node 24 on the host, `npm ci && npm run postinstall` does the same. On Windows, clone into a short path (or set `git config --global core.longpaths true`), and in Git Bash write the fourth command as `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)":/app -w /app node:24 sh -c "npm ci && npm run postinstall"`. On a cold Docker cache that command can take several minutes. The seed loads the committed fixtures through the real importers, so every record already carries its `sources[]`.

## The data in seven calls

Labels default to Romanian; `?lang=en` switches to English (see [Languages](docs/api.md#languages)). Every request and response block below is generated from a rendered example in `packages/vehicle-data-core/resources/examples/`, not hand-typed, and checked against the rendered file by `DocSnippetsTest` - exactly, for a full block; line by line, for a partial one (marked `…`). The fixed `request_id` you'll see below (`00000000-0000-4000-8000-000000000000`) is a normalised placeholder the renderer substitutes for a value that would otherwise change on every run, not real output.

**1. Taxonomies, with their stable ids.** Every controlled vocabulary - `fuel`, `eu_category`, `euro_norm`, `national_category` and more - has an immutable id: store it, not the name, in your own database.

<!-- request: taxonomies -->
```http
GET /v1/taxonomies HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: taxonomies partial path=data -->
```json
[
    {
        "id": "36DWE4ZD26Y2DCCEBKM7594AW0",
        "name": "body_type",
        "label": "Tip caroserie",
        "description": "Body style (no source in v1; terms only)",
        "term_count": 9
    },
    {
        "id": "MAFJJKCGSNGMBV4SXMS9EHJ2YP",
        "name": "colour",
        "label": "Culoare",
        "description": "Exterior colour family (no source in v1; terms only)",
        "term_count": 13
    },
    …
]
```

**2. One make, with its provenance.** A single record carries `sources[]` (never just `data`), so you always know which licence and attribution to show alongside it.

<!-- request: make-dacia -->
```http
GET /v1/makes/dacia HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: make-dacia -->
```json
{
    "data": {
        "id": "JA47BF995RFMPBB3ECP36BM0QR",
        "slug": "dacia",
        "name": "Dacia",
        "kind": "car",
        "manufacturer": "dacia",
        "ro_fleet": {
            "count": 10415,
            "year": 2025
        }
    },
    "sources": [
        {
            "key": "eea",
            "licence": "CC-BY-4.0",
            "attribution": "Source: European Environment Agency (EEA)",
            "retrieved_at": "2026-01-01T00:00:00+00:00"
        },
        {
            "key": "ro-fleet",
            "licence": "OGL-ROU-1.0",
            "attribution": "Contains public information under the Open Government Licence v1.0",
            "retrieved_at": "2026-01-01T00:00:00+00:00"
        },
        {
            "key": "wikidata",
            "licence": "CC0-1.0",
            "attribution": "Data from Wikidata (CC0)",
            "retrieved_at": "2026-01-01T00:00:00+00:00"
        }
    ],
    "meta": {
        "generated_at": "2026-01-01T00:00:00+00:00",
        "lang": "ro"
    }
}
```

`ro_fleet` here is a sum over the seeded sample, not a national total (see the note at the top).

The same record by its id - store ids, show slugs (see [Identifiers](docs/api.md#identifiers)):

<!-- request: make-dacia-by-id -->
```http
GET /v1/makes/JA47BF995RFMPBB3ECP36BM0QR HTTP/1.1
Authorization: Bearer vd_live_…
```

returns the identical body above.

**3. Its models, by fleet size.** Sorting and pagination work the same way on every list route. With the seeded data, "fleet size" is the sample's count, so this order says nothing about the national fleet.

<!-- request: make-dacia-models -->
```http
GET /v1/makes/dacia/models?sort=-ro_fleet_count&per_page=3 HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: make-dacia-models partial path=data -->
```json
[
    {
        "slug": "dacia-logan",
        …
        "ro_fleet": {
            "count": 6651,
            "year": 2025
        }
    },
    {
        "slug": "dacia-duster",
        …
        "ro_fleet": {
            "count": 3274,
            "year": 2025
        }
    },
    {
        "slug": "dacia-dokker",
        …
        "ro_fleet": {
            "count": 181,
            "year": 2025
        }
    }
]
```

**4. Diesel, Euro 6d Duster variants.** `fuel` and `euro_norm` are taxonomy codes you filter on directly.

<!-- request: variants-duster-diesel-euro6d -->
```http
GET /v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: variants-duster-diesel-euro6d partial path=data.0 -->
```json
{
    "id": "K2DXDXYXNC987T5XXV6CR64ARP",
    …
    "fuel": {
        "code": "diesel",
        "label": "Motorină"
    },
    "eu_category": {
        "code": "m1",
        "label": "Autoturism (M1)"
    },
    "euro_norm": {
        "code": "euro_6d",
        "label": "Euro 6d"
    },
    "engine_cc": 1461,
    "power_kw": 84,
    …
    "co2_wltp": 141,
    …
}
```

**5. N1 (light commercial) Ducato variants.** `eu_category` is the EU type-approval class (`m1` a car, `n1` a goods vehicle ≤ 3.5 t) - not a body style - so it filters the same way across every make.

<!-- request: variants-ducato-n1 -->
```http
GET /v1/models/fiat-ducato/variants?eu_category=n1 HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: variants-ducato-n1 partial path=data.0 -->
```json
{
    "id": "GZQM5C6N68KTHDQY70EK0JDY8H",
    …
    "fuel": {
        "code": "diesel",
        "label": "Motorină"
    },
    "eu_category": {
        "code": "n1",
        "label": "Autovehicul de marfă ≤ 3,5 t (N1)"
    },
    "euro_norm": {
        "code": "euro_6",
        "label": "Euro 6"
    },
    "engine_cc": 2184,
    "power_kw": 103,
    …
    "co2_wltp": 239,
    …
}
```

**6. `national_category` labels in English.** `?lang=en` switches every label without touching codes, slugs or ids.

<!-- request: taxonomy-national-category-en -->
```http
GET /v1/taxonomies/national_category?lang=en HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: taxonomy-national-category-en partial path=data.terms -->
```json
[
    {
        "id": "BZDNY00E90RJJ17FQ1KFZAHCBQ",
        "code": "autoturism",
        "label": "Passenger car",
        "sort_order": 0,
        "parent_code": null
    },
    {
        "id": "CPPC3R786FNS97S69M4QQ3SHJ5",
        "code": "autoutilitara",
        "label": "Light commercial vehicle",
        "sort_order": 1,
        "parent_code": null
    },
    {
        "id": "87R9WC9QZJPMQ09X0TXXDBY2JP",
        "code": "autobuz",
        "label": "Bus",
        "sort_order": 2,
        "parent_code": null
    },
    …
]
```

**7. A structural VIN decode.** Not a registration or history lookup - a decode of the VIN's own structure per ISO 3779.

<!-- request: vin -->
```http
GET /v1/vin/WVWZZZ3CZWE000001 HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: vin partial path=data -->
```json
{
    "vin": "WVWZZZ3CZWE000001",
    "wmi": "WVW",
    …
    "manufacturer": {
        "name": "VOLKSWAGEN AG",
        "country_code": "DE"
    },
    …
    "confidence": "high",
    "note": "Structural decode per ISO 3779 (WMI, check digit, model-year character, plant code). This is not a registration or history lookup."
}
```

**And one deliberate failure.** An out-of-range `per_page` fails hard with a `422` problem, never a silently clamped value.

<!-- request: error-422 -->
```http
GET /v1/makes?per_page=500 HTTP/1.1
Authorization: Bearer vd_live_…
```
<!-- example: error-422 -->
```json
{
    "type": "/problems/validation",
    "title": "Unprocessable Content",
    "status": 422,
    "detail": "One or more query parameters are invalid.",
    "instance": "/v1/makes",
    "request_id": "00000000-0000-4000-8000-000000000000",
    "errors": [
        {
            "field": "per_page",
            "code": "invalid",
            "message": "The per page field must not be greater than 100."
        }
    ]
}
```

All of these are rendered from the seed and checked in CI (`vehicle:examples render --check`, `DocSnippetsTest`); the runnable versions are in [examples/](examples/).

Every call except `GET /v1/health`, `GET /openapi.yaml` and `/docs` needs `Authorization: Bearer <key>`.

## What this demonstrates

- **Laravel 13 application plus an in-repo Composer package** (`packages/vehicle-data-core`) that owns the domain, HTTP layer, importers and commands, exposing four extension interfaces (`DataSource`, `Enricher`, `SpecificationSchema`, `ClientResolver` - at v1.0.0 the core itself implements all but `Enricher`, and a new `DataSource` must map to one of the built-in record types) and nine decision records: [ADRs](docs/adr/).
- **OpenAPI-first, with hashed bearer keys and scopes:** a hand-written OpenAPI 3.0.3 document, linted with Spectral, every response validated by Spectator contract tests, breaking changes blocked on pull requests by oasdiff unless labelled `breaking`: [contract](packages/vehicle-data-core/resources/openapi/openapi.yaml).
- **Quality gates in CI:** Pest (unit, feature and contract tests on MariaDB, importer and seed tests) with a 90 % coverage floor of the package code, PHPStan level 8 without a baseline, Pint, a route snapshot, a p95 assertion and a Playwright check of `/docs`, gitleaks, a hash-based blocklist and `composer audit`: [quality](docs/quality.md).
- **Capacity measured, not promised:** 65.5 rps at 100 concurrent users with no think time (32.8 rps with 1–5 s think time) on a two-CPU laptop container, 0 errors - measured 2026-09-17, before ADR 0008's extra auth query; not re-measured: [capacity](docs/quality.md#capacity).
- **Verified end to end:** every marked request line and response body in this README and `docs/api.md` is rendered from the seeded example data and checked by `DocSnippetsTest`; the same walkthrough runs as curl, JavaScript, PHP and Python clients, executed in CI: [examples](examples/).
- **Reviewed against its own claims:** an architecture review with a recorded decision, and a workaround, for every open finding: [known limitations](docs/known-limitations.md).

## Data and licences

| Source | What | Licence | Attribution |
|---|---|---|---|
| EEA CO2 monitoring of passenger cars | variants: fuel, EU category, engine, power, mass, CO2 (WLTP), emission stage | CC BY 4.0 | "Source: European Environment Agency (EEA)" |
| DRPCIV Parc auto România (data.gov.ro) | Romanian fleet counts per make and model | OGL-ROU 1.0 | "Contains public information under the Open Government Licence v1.0" |
| Wikidata | manufacturers: country, founding year, parent, website, Commons logo reference | CC0 1.0 | "Data from Wikidata (CC0)" (courtesy; not required) |
| NHTSA vPIC | World Manufacturer Identifiers for the VIN decoder | US public domain | "Source: NHTSA vPIC" (courtesy; not required) |

API output is provided under the licences of the sources it comes from; no additional database right is claimed over the compilation. Values are normalised on import (a change to the source data for attribution purposes) and provided as is, without warranty. Single records carry `sources[]`; lists, CSV and snapshots carry an `X-Data-Attribution` header.

Full detail, fixture checksums, known data issues and example coverage per taxonomy term: [docs/data-sources.md](docs/data-sources.md). No scraping, no marketplace data, no prices. Logos are referenced by Wikimedia Commons file name and licence only (public domain or CC BY without share-alike) and remain trademarks of their owners.

## Tiers

Public core (this repository): the open-data backbone, complete and deployable on its own. Private extension (a separate repository): enriched facets, further vehicle kinds and the live deployment; it pins this repository as a Git submodule at a release tag and registers its own `Enricher`, `DataSource`, `SpecificationSchema` and `ClientResolver` implementations. This repository itself is not operated as a public service. How the extension is bootstrapped: [docs/platform-bootstrap.md](docs/platform-bootstrap.md).

## Getting a key

Keys are issued by the operator with `php artisan vehicle:client create` (scopes `catalogue:read`, `vin:decode`, `snapshot:read`; `--rate` requests per minute and `--quota` requests per day per client; browser origins allow-listed with `--origins`). A key is shown once and stored only as a SHA-256 hash. There is no self-service sign-up in the core. Calling the API from a website (CORS, rate limits, caching, errors): [docs/api.md](docs/api.md).

## Documentation

- [docs/api.md](docs/api.md): conventions (authentication, CORS, limits, envelope, field classes, languages, lists, formats, caching, errors, evolution)
- [examples/](examples/): the same walkthrough runnable as curl, JavaScript, PHP and Python, plus a CSV/snapshot recipe
- `/docs` (Scalar) and `/openapi.yaml`: the contract, which is the source of truth for every response
- [docs/data-sources.md](docs/data-sources.md): sources, licences, attribution, fixtures, coverage
- [docs/quality.md](docs/quality.md): quality targets and how each is enforced
- [docs/deployment-cpanel.md](docs/deployment-cpanel.md): running it on shared hosting (one cron line, no Redis)
- [docs/release-gate.md](docs/release-gate.md): the three release gates and their results
- [docs/adr/](docs/adr/): architecture decision records 0001–0009
- [docs/architecture-review.md](docs/architecture-review.md): verified fixes, unresolved risks and the recommended implementation order, read before the first release
- [docs/known-limitations.md](docs/known-limitations.md): a decision for every open finding of the architecture review and of the independent review of v1.0.0
- [docs/roadmap.md](docs/roadmap.md): what v2 changes to make the data reliable at full size, safer by default and faster to import
- [docs/engineering-notes.md](docs/engineering-notes.md): decisions and their cost, where the numbers come from, what's deliberately not built, how it was reviewed
- [CHANGELOG.md](CHANGELOG.md) · [SECURITY.md](SECURITY.md) · [CONTRIBUTING.md](CONTRIBUTING.md) · [AGENTS.md](AGENTS.md)

## Status and roadmap

v1 (1.0.x) is a reference implementation with sample data (see the note at the top); security fixes and compatible corrections ship as 1.0.x patch releases. Cars only. The taxonomies `body_type`, `gearbox`, `drive` and `colour` are defined with ro/en labels but no source populates them yet; vehicle kinds beyond `car` exist only in the private extension. Additive changes stay in `/v1`; breaking changes ship as `/v2`, with `Deprecation` and `Sunset` headers on the `/v1` routes they replace. Open findings and the decision taken for each: [docs/known-limitations.md](docs/known-limitations.md). What v2 changes: [docs/roadmap.md](docs/roadmap.md).

## Licence

MIT for the code ([LICENSE](LICENSE)). The committed data fixtures in `packages/vehicle-data-core/database/fixtures/` are not covered by the MIT licence: they remain under their sources' licences, listed per file in [packages/vehicle-data-core/database/fixtures/LICENSE.md](packages/vehicle-data-core/database/fixtures/LICENSE.md) (see also [NOTICE](NOTICE)).
