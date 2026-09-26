# Changelog

All notable changes to this project are documented here. The format follows Keep a Changelog; versions follow SemVer. The public contract is `packages/vehicle-data-core/resources/openapi/openapi.yaml`: additive changes are minor releases, breaking changes are a new major version served under a new path prefix (`/v2`).

## [1.0.0] — 2026-09-26

### Added
- Endpoints (all `GET`): `/v1/health` (anonymous) and `/v1/health/ready`; `/v1/taxonomies` and `/v1/taxonomies/{key}`; `/v1/manufacturers` and `/v1/manufacturers/{key}`; `/v1/makes`, `/v1/makes/{key}` and `/v1/makes/{key}/models`; `/v1/models/{key}` and `/v1/models/{key}/variants`; `/v1/variants/{id}`; `/v1/vin/{vin}` (structural VIN decode); `/v1/snapshots/{manufacturers|makes|models|variants}` (gzipped JSON Lines artifact with `X-Data-Licences`, `X-Data-Attribution` and a `rel="license"` link).
- Immutable `id` on manufacturers, makes, models and variants (ADR 0007); keyed routes accept an id or a slug; retired slugs answer 301 (`vd_slug_aliases`); import identity by raw source value.
- Per-record provenance in `sources[]` on single records, and an `X-Data-Attribution` header (attribution of every source present) on JSON lists, CSV and snapshots.
- Lists with pagination, `sort`, sparse `fields`, `updated_since` (date or date-time), prefix search `q` (manufacturers, makes, models) and per-resource filters; CSV on list routes (`?format=csv` or `Accept: text/csv`); Romanian labels by default and English with `lang=en` or `Accept-Language`.
- Three field classes enforced in resources and contract tests: never null, always present but nullable, omitted when absent. A manufacturer `logo` is served only with its admitted Commons licence.
- Kind registry (`SpecificationSchema`, `KindRegistry`) with the `car` kind; the OpenAPI document is assembled with every registered kind; `GET /v1/makes?kind=` answers 422 for an unregistered kind.
- Importers for EEA CO2 monitoring (Discodata), DRPCIV Parc auto România (data.gov.ro), Wikidata manufacturers (with Wikimedia Commons logo licence check) and NHTSA vPIC WMIs: `vehicle:import`, name normalisation, reject reports, per-record provenance in `sources[]`, committed fixtures of at most 200 rows each and `ExampleDataSeeder`.
- Operator commands: `vehicle:client` (create, rotate, revoke, list, show), `vehicle:sources` (list, report, check), `vehicle:usage` (aggregate, purge-ip, report), `vehicle:status`, `vehicle:logs tail`; the scheduler behind one cron line.
- OpenAPI 3.0.3 contract at `/openapi.yaml` (including `413`/`500` problems, the 403 problem members and the list, CSV and snapshot headers) and the self-hosted Scalar reference at `/docs`.
- Quality and delivery: Pest unit, feature, contract, importer and seed suites on MariaDB with a 90 % coverage floor, PHPStan level 8, Pint, Spectral, oasdiff on pull requests, a route snapshot, p95 assertions, a Playwright check of `/docs`, the `build` job (release tarball and Docker image), a deploy dry run, the reusable `deploy.yml` workflow and `scripts/deploy.sh` with automatic rollback.
- Licensing of committed data: `packages/vehicle-data-core/database/fixtures/LICENSE.md` (licence and attribution per fixture file) and `NOTICE`; `docs/data-sources.md` states the licence of API output, known data issues and what each import count means.
- Documentation: README, `docs/api.md`, `docs/data-sources.md`, `docs/quality.md`, `docs/deployment-cpanel.md`, `docs/release-gate.md`, `docs/platform-bootstrap.md`, ADRs 0001–0009, SECURITY, CONTRIBUTING, AGENTS; `scripts/release_check.php` and the `release-check` CI job on version tags.
- Bounded retention: `vehicle:usage prune` (raw requests, 90 days), `vehicle:cache gc`, `vehicle:logs prune` (dated import/status logs), all on the daily schedule; retention table in the runbook.
- `scripts/load.php` and `make load`; measured capacity in docs/quality.md.
- Architecture review with evidence, tradeoffs, prioritized open findings and acceptance criteria; updated release, security and extension guidance.
- `docs/known-limitations.md`: a decision for every finding of the architecture review.
- Taxonomy and term public ids, renamed-key reservation and taxonomy-name redirects (ADR 0009).
- Verified examples: `vehicle:examples render` with `--check`, committed rendered responses, real examples merged into the served OpenAPI document, `examples/` clients (curl, JavaScript, PHP, Python) executed in CI, `DocSnippetsTest`.
- `docs/engineering-notes.md`.

### Changed
- The first id of an imported record is minted deterministically from its provenance ref (ADR 0007 addendum); ids remain immutable.
- `RecordWriter::write()` (the extension-point interface in `packages/vehicle-data-core/src/Contracts/RecordWriter.php`, see `docs/platform-bootstrap.md`) gained a third parameter, `\DateTimeInterface $retrievedAt`: the import run's single captured instant, threaded through to `CatalogueIdentity::make()`/`model()` so their own provenance writes stay constant for the whole run instead of firing an `UPDATE` per row. A breaking change to this public SPI for any `RecordWriter` implementation registered outside the core.
- ETags are weak validators (W/"…"), compared weakly, honouring If-None-Match: * and tag lists, on GET and HEAD (R7 of the architecture review).

### Security
- Bearer keys `vd_live_` + 40 base62 characters, stored as SHA-256 hashes with an 8-character prefix, shown once; rotation and revocation by command; optional expiry.
- Scopes `catalogue:read`, `vin:decode`, `snapshot:read`; per-client rate limit per minute and daily quota; failed authentication throttled per IP. Rate limit, quota and failed-authentication counters live in `vd_api_counters` (one statement per window); resolved keys are cached for a minute; `last_used_at` is set by the daily aggregate.
- Every keyed response is `Cache-Control: private` with `Vary: Authorization, Origin`, so shared caches never serve it without a key.
- Per-key CORS allow-lists with an anonymous preflight; hardening headers (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, a strict `Content-Security-Policy`); request body cap; RFC 9457 problem responses that never leak internals; CSV formula-injection escaping.
- Log files created with a configurable mode (`LOG_FILE_PERMISSION`, default `0664`; `0666` in the Docker development `.env.example`). Request IPs purged after 30 days; logs carry only the key prefix; production refuses to boot with `APP_DEBUG=true`.
- Publishable-commit hygiene: hash-based blocklist, gitleaks and a 100 KB file cap in the pre-commit hook, with the blocklist and gitleaks also in CI.
- The per-IP failed-authentication limit blocks only failed attempts; a valid key from a shared address is never locked out.
- Development Compose ports bind to loopback by default.
- Corrected the monitoring privacy guidance: Sentry's default-PII setting does not scrub URLs, all headers or exception context. Keep telemetry disabled until sanitization is verified.
- Importers refuse a source whose licence is not admitted before any write (R12).
- Outside `local` and `testing`, only the host of `APP_URL` is trusted: a request with another `Host` answers `400`, so it is never reflected into `servers[0].url` of `/openapi.yaml`.
- `vehicle:examples` asks for confirmation in production (`--force` skips it) and refuses an `--openapi-out` path that is a symbolic link or resolves into the package resources; the example clients never send the key to another origin on a redirect.
- `scripts/blocklist.php` runs git without a shell and exits 2 when git fails or `git log` yields no commit, so `scan-history` can no longer pass without scanning commit messages and identities (it did on Windows).

### Fixed
- A second checkout no longer recreates the first one's containers: the Compose project name is overridable (`COMPOSE_PROJECT_NAME`).
- Variant CSV: `fuel`, `eu_category` and `euro_norm` columns are `<field>.code`/`<field>.label` (they were flattened term objects, so the declared header didn't match the data and the cells were empty); `specifications` is JSON-encoded into a single cell instead of being flattened.
- `vehicle:logs tail` and usage rows never log a raw, unmatched request path (e.g. a VIN-like value) — an unmatched or unnamed route logs `unmatched`.
- The authentication-failure log line no longer includes the caller's IP (it is still rate-limited by IP, and retained only in `vd_api_requests` per `core.request_ip_retention_days`).
- The local storage disk no longer registers the `storage/{path}` GET/PUT routes (`serve: false`); nothing in this API serves or uploads through local-disk storage.
- `DatabaseSeeder` no longer suppresses model events, so the `creating` event that assigns `id` (ADR 0007) fires during `migrate --seed`.
- `vehicle:client create` validates name, owner, rate, quota, origins and expiry before writing, instead of failing with a database error.
- `openapi.yaml` now declares `X-Request-Id` (sent on every response), `X-RateLimit-Limit` and `X-RateLimit-Remaining` (sent on every keyed response once the throttle has run), and the CSV/snapshot-only `Content-Disposition` header — the contract previously omitted all four.
- Cached client metadata is checked against active credential state on the primary database before reuse, preventing a late cache write from restoring a revoked or rotated key (ADR 0008).
- Enrichers cannot remove protected fields whose value is null; readiness reports 503/degraded when its cache probe fails.
- Client creation deduplicates scopes and commits the client and scopes atomically before printing the key.

### Notes
- Cars only; the `body_type`, `gearbox`, `drive` and `colour` taxonomies are defined but not yet populated by any source.
- The contract is OpenAPI 3.0.3, not 3.1, because the PHP response validators parse only 3.0.x (ADR 0003).
- Requires PHP 8.4 or later; CI pins third-party GitHub Actions to commit SHAs and runs MariaDB 10.5 (hosting parity) and 10.11 (the supported baseline) as required test legs.
