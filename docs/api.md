# API conventions

The contract is the OpenAPI 3.0.3 document at `GET /openapi.yaml` (source: `packages/vehicle-data-core/resources/openapi/openapi.yaml`), rendered at `/docs`. This page explains the conventions every route follows, with one example each. Examples are real responses from the seeded example data, shortened with `…`; keys are shown as `vd_live_…`. Fixed-looking values you'll see repeated below - the all-zero `ETag`, the request id `00000000-0000-4000-8000-000000000000`, timestamps such as `2026-01-01T00:00:00+00:00`, and the `Retry-After` on the 429 example - are normalised placeholders the example renderer substitutes for values that would otherwise change on every run, not real server output.

Routes (all `GET`; `HEAD` is answered too, any other method is `405`): `GET /v1/health`, `/v1/health/ready`, `/v1/taxonomies`, `/v1/taxonomies/{key}`, `/v1/manufacturers`, `/v1/manufacturers/{key}`, `/v1/makes`, `/v1/makes/{key}`, `/v1/makes/{key}/models`, `/v1/models/{key}`, `/v1/models/{key}/variants`, `/v1/variants/{id}`, `/v1/vin/{vin}`, `/v1/snapshots/{resource}`, and `GET /openapi.yaml`. `{key}` accepts an id or a readable current key (see Identifiers below); variants have only `id`. Every route is read-only.

## Authentication

Every `/v1` route except `GET /v1/health` needs a bearer key: `Authorization: Bearer vd_live_<40 base62 characters>`. `GET /openapi.yaml` and `/docs` are anonymous. A missing, unknown, revoked or expired key gets `401` with `WWW-Authenticate`.

<!-- request: error-401 -->
```http
GET /v1/makes HTTP/1.1
```

```http
HTTP/1.1 401 Unauthorized
Content-Type: application/problem+json
WWW-Authenticate: Bearer realm="vehicle-data-api"
```
<!-- example: error-401 -->
```json
{
    "type": "/problems/unauthenticated",
    "title": "Unauthorized",
    "status": 401,
    "detail": "A valid bearer API key is required.",
    "instance": "/v1/makes",
    "request_id": "00000000-0000-4000-8000-000000000000"
}
```

Keys are issued by the operator (`php artisan vehicle:client create --name=… --owner=… --scopes=… --origins=… --rate=60 --quota=10000 [--expires=YYYY-MM-DD]`) and printed once; the server stores only a SHA-256 hash and the 8-character prefix. `create` validates its options before writing anything: `--rate` 1–65535, `--quota` 1–4 294 967 295, `--origins` `http(s)://host[:port]` (no path, no default port for the scheme). Revocation and rotation take effect immediately; the server caches a resolved key for at most a minute otherwise. `vehicle:client rotate --id=<id>` prints a new key and invalidates the old one at once, so deploy the new key before rotating in a live integration; `vehicle:client revoke --id=<id>` disables a client. More than `CORE_AUTH_FAIL_PER_MINUTE` (default 30) failed attempts per minute from one IP get `429` (`/problems/rate-limited`) with `Retry-After`. Only failed attempts count and only failed attempts are blocked; a valid key from the same address keeps working.

Cached client metadata does not authorize a revoked credential: each cache hit checks the key's active state on the primary database (ADR 0008). Requests already authenticated when a revocation or rotation commits may finish. Client creation writes the client and its deduplicated scopes atomically before printing the key.

## Scopes

| Scope | Routes |
|---|---|
| `catalogue:read` | taxonomies, manufacturers, makes, models, variants |
| `vin:decode` | `/v1/vin/{vin}` |
| `snapshot:read` | `/v1/snapshots/{resource}` |
| any valid key | `/v1/health/ready` |

<!-- request: error-403-scope -->
```http
GET /v1/vin/WVWZZZ3CZWE000001 HTTP/1.1
Authorization: Bearer vd_live_…            (a key with catalogue:read only)
```

```http
HTTP/1.1 403 Forbidden
```
<!-- example: error-403-scope -->
```json
{
    "type": "/problems/insufficient-scope",
    "title": "Forbidden",
    "status": 403,
    "detail": "This key lacks the 'vin:decode' scope.",
    "instance": "/v1/vin/WVWZZZ3CZWE000001",
    "request_id": "00000000-0000-4000-8000-000000000000",
    "required_scope": "vin:decode"
}
```

A scope failure still counts against the client's rate limit.

## CORS (calling from a browser)

Each key has its own list of allowed origins (`--origins=https://www.example.org,https://example.org`), matched exactly. A request without an `Origin` header (server to server) is not checked. A request whose `Origin` is not on the key's list gets `403` `/problems/origin-not-allowed`. Allowed responses carry `Access-Control-Allow-Origin: <origin>`, `Vary: Authorization, Origin` and `Access-Control-Expose-Headers: ETag, X-Request-Id, Content-Language, Deprecation, Sunset, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-Data-Attribution, X-Data-Licences`.

The preflight `OPTIONS /v1/*` needs no key (browsers never send one) and deliberately does not consult the allow-list: any syntactically valid `Origin` (`http(s)://host[:port]`, no path) gets the same `204`, so an unkeyed caller cannot probe which origins are registered. A missing or malformed `Origin` gets `403`. The per-key allow-list is enforced on the real request, which is what the browser gates on.

```http
OPTIONS /v1/makes HTTP/1.1
Origin: http://localhost:8087
Access-Control-Request-Method: GET

HTTP/1.1 204 No Content
Access-Control-Allow-Origin: http://localhost:8087
Access-Control-Allow-Methods: GET, OPTIONS
Access-Control-Allow-Headers: Authorization, Accept, Accept-Language, If-None-Match, X-Request-Id
Access-Control-Max-Age: 600
Vary: Origin
```

A key used in a web page is visible to its visitors: give it `catalogue:read` only, a low rate and a narrow origin list, or call the API from your server instead.

## Rate limits and quotas

Each client has a per-minute rate (`--rate`, default 60) and a daily quota in UTC days (`--quota`, default 10 000). Successful responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining` (per minute).

| Problem type | Status | When | Headers |
|---|---|---|---|
| `/problems/rate-limited` | 429 | per-minute rate exceeded | `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0` |
| `/problems/quota-exceeded` | 429 | daily quota used up | `Retry-After` (seconds until UTC midnight) |
| `/problems/rate-limited` | 429 | too many failed authentications from one IP | `Retry-After` |

This example is rendered against a key throttled to 1 request per minute for illustration (every other example on this page uses the documented default, 60): the render sends one request that succeeds, then this second one, which is over the limit.

<!-- request: error-429 -->
```http
GET /v1/makes HTTP/1.1
Authorization: Bearer vd_live_…            (a key throttled to 1 request/minute, for illustration)
```

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 56
X-RateLimit-Limit: 1
X-RateLimit-Remaining: 0
```
<!-- example: error-429 -->
```json
{
    "type": "/problems/rate-limited",
    "title": "Too Many Requests",
    "status": 429,
    "detail": "Rate limit of 1 requests per minute exceeded.",
    "instance": "/v1/makes",
    "request_id": "00000000-0000-4000-8000-000000000000"
}
```

Honour `Retry-After`; do not retry in a tight loop.

## Envelope

- **Lists**: `data` (array), `meta` (`page`, `per_page`, `total`, `generated_at`, `lang`), `links` (`self`, `next`, `prev`; `null` at the ends). The `X-Data-Attribution` header names the attribution of every source present on the page, `; `-separated (omitted on an empty page); show it wherever you display list data.
- **Single records** (manufacturer, make, model, variant, VIN): `data`, `sources`, `meta` (`generated_at`, `lang`). `sources[]` lists every source that contributed to the record, with `key`, `licence`, `attribution` and `retrieved_at`. Show the attribution wherever you display the data.
- **Taxonomies**: `data` and `meta` only (no `sources`: taxonomies are the project's own controlled vocabularies, not imported records).

<!-- request: make-dacia -->
```http
GET /v1/makes/dacia HTTP/1.1
Authorization: Bearer vd_live_…
```

```http
HTTP/1.1 200 OK
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

## Identifiers

Every catalogue record has two keys. `id` (26 characters, `[0-9A-HJKMNP-TV-Z]`) is assigned once and never changes: store it. (The id is derived from the source's own reference, or from the taxonomy name and code, so a fresh install of the same data, imported in the same order, yields the same ids.) `slug` is the readable URL key and can change when a source corrects a name; a retired slug keeps working and answers `301` to the current URL, query string included, so follow redirects. Keyed routes accept either value in the same position: `/v1/makes/dacia` and `/v1/makes/<id>` are the same resource (see the by-id example below). Cross-references (`make`, `model`, `manufacturer`, `parent`) are always current slugs. Variants have only `id`. On a list, `links.self` echoes the URL as requested (an id in the path stays an id there); only the `Location` of a redirect and other server-built URLs use the current slug.

```http
GET /v1/makes/vw HTTP/1.1
Authorization: Bearer vd_live_…

HTTP/1.1 301 Moved Permanently
Location: https://example.org/v1/makes/volkswagen
Cache-Control: max-age=86400, private
```

The redirect has no body and no `Content-Type`; the request still needs a valid key with the route's scope, like any other response - a missing or wrong-scope key gets `401`/`403` before the redirect is considered.

A retired slug reserves the namespace only within its own resource type: a make that gives up a slug does not stop a manufacturer (or model) from using the same one, and vice versa (ADR 0007).

Identifiers of other systems (a partner's numeric make id) are not fields of this API; they are provenance rows a private data source can attach (ADR 0007).

The same make addressed by its id, instead of its slug, returns the identical body - store ids, show slugs:

<!-- request: make-dacia-by-id -->
```http
GET /v1/makes/JA47BF995RFMPBB3ECP36BM0QR HTTP/1.1
Authorization: Bearer vd_live_…
```

```http
HTTP/1.1 200 OK
```
<!-- example: make-dacia-by-id partial path=data -->
```json
{
    "id": "JA47BF995RFMPBB3ECP36BM0QR",
    "slug": "dacia",
    …
}
```

## Taxonomy identifiers

Taxonomies are controlled vocabularies, not imported catalogue records. Each taxonomy and each term has an immutable 26-character public `id`. Store these IDs in foreign systems and use a taxonomy ID or its current `name` in `/v1/taxonomies/{key}`. `name` and `code` remain readable, current keys; never use labels or sort order as identity.

When a taxonomy is renamed, its old name is permanently reserved and `/v1/taxonomies/{old-name}` returns `301` to the current name, query string included. The public ID continues to answer `200`. When a term code is renamed, its old code is permanently reserved inside that taxonomy, remains accepted by the related variant filter during migration, and its public ID remains stable. A rename must run through `TaxonomyIdentity` in one transaction, retain the alias, update catalogue references and parent codes, and update the affected labels and translations before publication. It must reject any attempt to take another taxonomy's retired name or another term's retired code. This prevents a renamed key from being silently rebound to different meaning and protects stored references from data loss. See ADR 0009.

An ID is not the database's numeric primary key. It is a 26-character Crockford base32 public id. New taxonomy and term IDs are additive `/v1` fields; consumers that already stored `name + code` should migrate stored references to IDs before relying on future renames.

## Field classes

Enrichers must preserve both the presence and value of class-1 and class-2 keys, including null values. A violation is an internal error, not a successful response with a missing required field. Sparse field selection still applies afterward as documented below.

Every key belongs to one of three classes (ADR 0004). Unknown values are never `0` or `""`.

| Resource | Class 1: always present, never `null` | Class 2: always present, `null` when no source has it | Class 3: omitted when absent |
|---|---|---|---|
| manufacturer | `id`, `slug`, `name` | `country_code`, `founded_year`, `parent`, `website` | `logo` (`commons_file`, `licence`, `url`; present only with an admitted licence, never with a `null` licence) |
| make | `id`, `slug`, `name`, `kind` | `manufacturer` | `ro_fleet` (`count`, `year`) |
| model | `id`, `slug`, `name`, `make` | `first_year`, `last_year` | `ro_fleet` (`count`, `year`) |
| variant | `id`, `make`, `model`, `fuel`, `eu_category` | `euro_norm`, `engine_cc`, `power_kw`, `power_hp`, `mass_kg`, `co2_wltp`, `year_from`, `year_to` | `specifications` (kind-specific, see the kind's schema in the contract) |

<!-- example: manufacturer-bmw path=data -->
```json
{
    "id": "R7RR62Z0M3VZRRSY4AB53T2Q6X",
    "slug": "bmw",
    "name": "BMW",
    "country_code": "DE",
    "founded_year": 1916,
    "parent": null,
    "website": "https://www.bmw.com",
    "logo": {
        "commons_file": "Logo BMW Group 2021.svg",
        "licence": "Public domain",
        "url": "https://commons.wikimedia.org/wiki/File:Logo%20BMW%20Group%202021.svg"
    }
}
```

Taxonomy-coded values (`fuel`, `eu_category`, `euro_norm`) are objects `{"code": …, "label": …}`: branch on `code`, display `label`.

A `logo` is a reference, not an image: `licence` is the Wikimedia Commons licence short name (public domain, CC0 or CC BY without share-alike), and the Commons file page at `url` carries the author credit and full terms; when you display the logo, credit it as that page requires.

## Languages

Labels are Romanian by default. Choose with `?lang=ro|en` (any other value is `422`); without it, the first `Accept-Language` entry whose two-letter prefix is `ro` or `en` wins; otherwise `ro`. The response states the choice in `Content-Language` and `meta.lang`. Names are stored with `utf8mb4_romanian_ci` collation, so sorting by `name` follows Romanian rules (ș, ț with comma below). Codes, slugs and keys never change with the language.

<!-- request: taxonomy-national-category-en -->
```http
GET /v1/taxonomies/national_category?lang=en HTTP/1.1
Authorization: Bearer vd_live_…
```

```http
HTTP/1.1 200 OK
Content-Language: en
```
<!-- example: taxonomy-national-category-en partial path=data -->
```json
{
    "id": "H6RPPG0N4J7H2RWPGVX2WHSE1M",
    "name": "national_category",
    "label": "National category",
    "description": "Romanian registration category (DRPCIV categorie națională)",
    "terms": [
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
        …
    ]
}
```

The same call without `lang` returns the Romanian labels for these terms instead, and `meta.lang` becomes `"ro"`.

## Lists

Common query parameters on list routes:

| Parameter | Meaning |
|---|---|
| `page`, `per_page` | 1-based page; `per_page` 1–100, default 25 |
| `sort` | one of the route's sortable fields, `-` prefix for descending |
| `fields` | comma-separated sparse fieldset; class-1 keys are always included |
| `updated_since` | ISO 8601 date (`2026-09-01`) or date-time (`2026-09-01T12:00:00Z`); records changed at or after it |
| `q` | case-insensitive name prefix, 1–80 characters (manufacturers, makes, models; not variants) |

| Route | Sortable | Filters |
|---|---|---|
| `/v1/manufacturers` | `name` (default), `founded_year`, `country_code`, `updated_at` | `country_code` (ISO 3166-1 alpha-2) |
| `/v1/makes` | `name` (default), `ro_fleet_count`, `updated_at` | `kind` (a registered kind, default `car`), `manufacturer` (slug) |
| `/v1/makes/{key}/models` | `name` (default), `first_year`, `ro_fleet_count`, `updated_at` | - |
| `/v1/models/{key}/variants` | `power_kw` (default), `engine_cc`, `co2_wltp`, `year_from`, `updated_at` | `fuel`, `eu_category`, `euro_norm` (taxonomy codes), `year`, `power_kw_min`, `power_kw_max`, `engine_cc_min`, `engine_cc_max` |

An invalid parameter is `422` (see Errors).

<!-- request: variants-duster-diesel-euro6d -->
```http
GET /v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d HTTP/1.1
Authorization: Bearer vd_live_…
```

```http
HTTP/1.1 200 OK
X-Data-Attribution: Source: European Environment Agency (EEA)
```
<!-- example: variants-duster-diesel-euro6d -->
```json
{
    "data": [
        {
            "id": "K2DXDXYXNC987T5XXV6CR64ARP",
            "make": "dacia",
            "model": "dacia-duster",
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
            "power_hp": 114,
            "mass_kg": 1518,
            "co2_wltp": 141,
            "year_from": 2024,
            "year_to": null,
            "specifications": {
                "euro_stage_raw": "6AP",
                "fuel_mode": "M",
                "type_approval_number": "E2*2001/116*0323*75",
                "type_code": "SRD",
                "variant_code": "HD4",
                "version_code": "AD6UB2P0M0B0",
                "fuel_consumption_l_100km": 5.4
            }
        }
    ],
    "meta": {
        "page": 1,
        "per_page": 25,
        "total": 1,
        "generated_at": "2026-01-01T00:00:00+00:00",
        "lang": "ro"
    },
    "links": {
        "self": "http://localhost:8087/v1/models/dacia-duster/variants?fuel=diesel&euro_norm=euro_6d&page=1",
        "next": null,
        "prev": null
    }
}
```

## Representations

- **JSON** (`application/json`) everywhere; errors are `application/problem+json`.
- **CSV** on list routes only, with `?format=csv` or `Accept: text/csv`: UTF-8 with BOM, `;` delimiter, a fixed header row per route, nested keys flattened with dots (`ro_fleet.count`), `null` as an empty cell, cells starting with `=`, `+`, `-`, `@`, tab or carriage return prefixed with `'` (formula-injection guard), `Content-Disposition: attachment`, and the same `X-Data-Attribution` header as JSON lists. The current page only; use `per_page` and `page`.
- **Snapshots**: `GET /v1/snapshots/{manufacturers|makes|models|variants}` (scope `snapshot:read`, optional `updated_since`) streams the whole resource as a downloadable gzip file of JSON Lines, one record per line: `Content-Type: application/gzip`, `Content-Disposition: attachment; filename="makes-2026-09-17.ndjson.gz"`, `X-Data-Licences` (the licence ids of every source that contributed to that resource), `X-Data-Attribution` (their attribution statements) and `Link: <…/docs/data-sources.md>; rel="license"`. There is no `Content-Encoding` header: decompress the file yourself (`gunzip`). The route ignores `Accept` (no `406`) and sends no ETag.
- On the catalogue and VIN routes, any other `Accept` or `format`, or CSV on a single-record route, is `406`.

The body below starts with a UTF-8 byte-order mark (BOM), which Excel needs to open the file as UTF-8 rather than guessing a legacy codepage; it is not shown in the block (an invisible BOM pasted into Markdown would be indistinguishable from nothing).

<!-- request: makes-csv -->
```http
GET /v1/makes?format=csv&per_page=3 HTTP/1.1
Authorization: Bearer vd_live_…
```

```http
HTTP/1.1 200 OK
Content-Type: text/csv; charset=UTF-8
X-Data-Attribution: Source: European Environment Agency (EEA); Contains public information under the Open Government Licence v1.0; Data from Wikidata (CC0)
```
<!-- example: makes-csv -->
```csv
id;slug;name;kind;manufacturer;ro_fleet.count;ro_fleet.year
KHPH8CFY72YX7G6TZ706QV0D13;bmw;BMW;car;bmw;567;2025
JA47BF995RFMPBB3ECP36BM0QR;dacia;Dacia;car;dacia;10415;2025
E9ZEQCW0SFG7WRNTGB1MBQQTH7;fiat;Fiat;car;fiat;254;2025
```

## Caching

JSON `200` responses on the catalogue, taxonomy and VIN routes and on `/openapi.yaml` carry a weak `ETag` (`W/"…"`); send it back in `If-None-Match` (the strong form, a list or `*` also match) to get `304 Not Modified` with no body, on `GET` and `HEAD`. The ETag ignores `meta.generated_at` and differs per language. It is not computed for CSV, snapshots or error responses.

| Route class | `Cache-Control` |
|---|---|
| taxonomies, manufacturers, makes, models, variants, VIN (JSON and CSV) | `private, max-age=0, must-revalidate` |
| snapshots | `private, max-age=0` |
| `/openapi.yaml` (anonymous) | `public, max-age=300` |
| `301` for a retired slug (see Identifiers) | `max-age=86400, private` |
| health, errors | `no-store, private` |

v1.0.0 is the first release: the strong tag emitted before it was never a published promise.

Every keyed response is `private`, so a shared cache (CDN, proxy) never stores it and cannot serve it to a caller without a key; keyed responses also carry `Vary: Authorization, Origin`, plus `Accept-Language` on JSON routes. Your own server-side cache should key on the API key, the origin and the language.

<!-- request: taxonomy-fuel -->
```http
GET /v1/taxonomies/fuel HTTP/1.1
Authorization: Bearer vd_live_…
If-None-Match: W/"0000000000000000000000000000000000000000000000000000000000000000"
```

```http
HTTP/1.1 304 Not Modified
ETag: W/"0000000000000000000000000000000000000000000000000000000000000000"
Cache-Control: max-age=0, must-revalidate, private
Vary: Accept-Language
Vary: Authorization
Vary: Origin
```

## Errors

Every error is an RFC 9457 problem (`application/problem+json`) with `type`, `title`, `status`, `detail`, `instance` and `request_id`, plus type-specific members. Branch on `type`, not on `detail`.

| `type` | Status | Meaning | Extra members |
|---|---|---|---|
| `/problems/unauthenticated` | 401 | missing, unknown, revoked or expired key | - |
| `/problems/insufficient-scope` | 403 | the key lacks the route's scope | `required_scope` |
| `/problems/origin-not-allowed` | 403 | `Origin` not allowed for the key, or missing/malformed on a preflight | `origin` |
| `/problems/forbidden` | 403 | any other authorisation failure | - |
| `/problems/rate-limited` | 429 | per-minute rate, or failed-authentication throttle | - |
| `/problems/quota-exceeded` | 429 | daily quota used up | - |
| `/problems/validation` | 422 | invalid query parameter (including `lang`, `sort`, filters, unknown `kind`) | `errors[]`: `field`, `code`, `message` |
| `/problems/not-found` | 404 | unknown route, slug, id or snapshot resource | - |
| `/problems/malformed-vin` | 400 | the VIN is not 17 characters of A–Z (without I, O, Q) and 0–9 | - |
| `/problems/payload-too-large` | 413 | a request body over `CORE_MAX_BODY_BYTES` | - |
| `/problems/internal` | 500 | unexpected failure; no internals are exposed, quote the `request_id` | - |
| `about:blank` | 405, 406 | a method other than GET/HEAD; unsupported representation | - |

All of these, including `413` and `500`, are declared in the OpenAPI contract; `405` is described there once, since the contract lists only the GET operations.

<!-- request: error-422 -->
```http
GET /v1/makes?per_page=500 HTTP/1.1
Authorization: Bearer vd_live_…
```

```http
HTTP/1.1 422 Unprocessable Content
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

## Evolution

- **Additive changes stay in `/v1`**: new routes, optional parameters, keys, taxonomy terms and kinds may appear at any time. **Consumers must ignore unknown keys** and must not rely on key order.
- **Breaking changes ship as `/v2`**. The `/v1` routes being replaced then carry `Deprecation` (the date deprecation took effect), `Sunset` (the date they stop answering) and `Link: <…>; rel="deprecation"` (the migration notes). Watch for these headers.
- **Vehicle kinds**: `kind` (a make's class-1 key and the `/v1/makes?kind=` filter) comes from a registry. The core registers `car`; extensions register more kinds, each with its own `specifications` schema, which the contract at `/openapi.yaml` includes automatically. An unregistered `kind` is `422`.
- Changes are listed in `CHANGELOG.md`; pull requests that break the contract are blocked by an oasdiff check in CI.

## Readiness

Readiness (`/v1/health/ready`) returns HTTP 503 and `data.status=degraded` when its database or cache probe fails. Authentication and throttling run before these probes; a failure there can prevent the readiness payload from being produced. Queue backlog and import times are informational and need operator-defined alert thresholds.

## Request ids

Every response under `/v1`, `/openapi.yaml` and `/docs` carries `X-Request-Id`. Send your own UUID in `X-Request-Id` to correlate logs across systems; anything that is not a UUID is replaced by a fresh one. The same id appears as `request_id` in problem bodies and in the server's JSON log line for the request, so quote it when reporting a problem.

The application logs the route name (`v1.vin.show`), never the path, so a VIN never enters its logs; the web server in front of it may log full URLs under the operator's own retention (see the deployment runbook).

<!-- request: health -->
```http
GET /v1/health HTTP/1.1
```

```http
HTTP/1.1 200 OK
X-Request-Id: 00000000-0000-4000-8000-000000000000
Cache-Control: no-store, private
```
<!-- example: health -->
```json
{
    "data": {
        "status": "ok",
        "version": "dev",
        "time": "2026-01-01T00:00:00+00:00"
    }
}
```

This example didn't send its own `X-Request-Id`, so the value shown (`00000000-0000-4000-8000-000000000000`) is the render's normalised placeholder, not a fixed value the live server returns - send your own UUID and it comes back unchanged instead.
