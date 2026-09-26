# Contributing

These rules apply to every change, whether a person or an AI assistant writes it. The OpenAPI document (`packages/vehicle-data-core/resources/openapi/openapi.yaml`, served assembled at `/openapi.yaml`) is the source of truth for every response; `docs/api.md` explains the conventions behind it.

## Setup

Follow the six commands in the README ("Try it in six commands"), then enable the repository's Git hooks once:

```bash
git config core.hooksPath .githooks
```

Every PHP command runs inside the `app` container (`docker compose exec -T app <command>`): PHP 8.4, Composer, Pest, Pint and PHPStan are there. Node 24 tooling (Spectral, Playwright, the docs bundle) runs on the host or in a `node:24` container. `make up`, `make seed` and `make shell` are shortcuts for the Docker Compose commands. `docker compose exec` runs as root while the web server runs as `nobody`; `.env.example` sets `LOG_FILE_PERMISSION=0666` so a log file first created by a CLI run stays writable by the web server (keep the default `0664` on hosting).

## Where things live

- `app/`, `routes/web.php`, `bootstrap/app.php`: the thin Laravel application (the `/docs` page, middleware registration, exception rendering, the schedule in `routes/console.php`).
- `packages/vehicle-data-core/` (`VehicleData\Core`): models and migrations (`vd_` prefix), HTTP controllers, middleware and resources, importers, the VIN decoder, Artisan commands, the OpenAPI document and the tests of the package.
- `docs/adr/`: one record per architectural decision. Read ADR 0002 (public core and private extension) and ADR 0004 (field classes and evolution) before changing the API.

## Field classes

Every key of every resource belongs to one class, declared in the resource class (`CLASS1`, `CLASS2`, class-3 keys added through `Fields::optional()`) and checked by `packages/vehicle-data-core/tests/Contract/FieldClassContractTest.php`:

1. **Never null**: always present with a real value. Example: `id` (every resource), `slug`, `name`, `kind` (makes), `make`, `model`, `fuel`, `eu_category` (variants).
2. **Always present, may be `null`**: `null` means no held source provides the value. Example: `manufacturer` (makes), `first_year`, `last_year` (models), `euro_norm`, `engine_cc`, `power_kw`, `power_hp`, `mass_kg`, `co2_wltp`, `year_from`, `year_to` (variants), `country_code`, `founded_year`, `parent`, `website` (manufacturers).
3. **Omitted when absent**: the key appears only when there is a value. Example: `ro_fleet` (makes, models), `logo` (manufacturers), `specifications` (variants).

Never use `0`, `""` or a placeholder for an unknown value. A new key starts in class 3 or class 2; promoting a key to a stricter class is allowed, demoting it is a breaking change.

## Evolution rules

- **Additive changes stay in `/v1`**: a new route, a new optional query parameter, a new key, a new taxonomy term, a new kind. Consumers are told to ignore unknown keys.
- **Breaking changes ship as `/v2`**: removing or renaming a key or route, changing a type, demoting a field class, making a parameter required, changing a default. The `/v1` routes being replaced get `Deprecation`, `Sunset` and `Link` headers through `core.deprecations`.
- **Every schema change comes as one pull request with all four parts**: an ADR in `docs/adr/` if the decision is not already recorded, an additive migration (never edit a merged migration; no destructive down-migrations in deploys), the OpenAPI change with a matching contract test, and a CHANGELOG entry under `[Unreleased]` (add an `## [Unreleased]` section above the latest release when there is none).
- The `contract` CI job runs Spectral, the Spectator contract tests and, on pull requests, **oasdiff against the base branch**, which fails on any breaking change. A deliberate breaking change needs the `breaking` label on the pull request (create the label the first time) and a new major version.

## The four extension contracts

The core is extended without forking it. The contracts live in `packages/vehicle-data-core/src/Contracts/`; the core's implementations are registered in `src/Providers/CoreServiceProvider.php`; a private extension registers its own in its own service provider (see `docs/platform-bootstrap.md`).

| Contract | Purpose | Core implementations | How an extension adds one |
|---|---|---|---|
| `DataSource` | Fetch and map rows from one licensed source | `src/Importers/Sources/` (`eea`, `ro-fleet`, `wikidata`, `wmi`) | Register it in `SourceRegistry`, with its own `Licence`; at v1.0.0 its rows must map to a built-in record type (`variant`, `fleet`, `manufacturer`, `wmi`) |
| `Enricher` | Add class-3 keys to a serialised record; may never change class-1/2 keys | none in the core (only the tagging seam and tests) | Tag it `core.enrichers` in the container |
| `SpecificationSchema` | JSON Schema and OpenAPI fragment for a vehicle kind's `specifications` | `src/Kinds/Schemas/CarSpecificationSchema.php` | Register it in `KindRegistry`; the OpenAPI assembler adds its fragment |
| `ClientResolver` | Resolve a bearer key to a client with scopes, limits and origins | `src/Auth/DatabaseClientResolver.php` | Rebind `ClientResolver` in the container |

`RecordWriter` and `RunAware` in the same directory are internal to the import pipeline (one writer per domain row type), not extension points.

## Tests

| Layer | Directory | Group | Database |
|---|---|---|---|
| Unit | `packages/vehicle-data-core/tests/Unit`, `tests/Unit` | — | none |
| Feature | `packages/vehicle-data-core/tests/Feature`, `tests/Feature` | — | MariaDB, `RefreshDatabase` |
| Contract (Spectator against the OpenAPI document) | `packages/vehicle-data-core/tests/Contract` | `contract` | MariaDB |
| Importer (fixtures through the real pipeline) | `packages/vehicle-data-core/tests/Importer` | `importer` | MariaDB |
| Seed | `packages/vehicle-data-core/tests/Seed` | `seed` | MariaDB |
| Examples (the rendered examples and the `docs/api.md`/README snippets) | `packages/vehicle-data-core/tests/Examples` | `examples` | MariaDB |
| Live downloads (opt-in, never in CI) | `packages/vehicle-data-core/tests/Live` | `network` | MariaDB |
| Browser (Playwright, `/docs`) | `tests/Browser` | `browser` | the running app |

Before proposing a change, run (all green, output clean):

```bash
docker compose exec -T app vendor/bin/pest --exclude-group=network --exclude-group=browser
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G
npx spectral lint packages/vehicle-data-core/resources/openapi/openapi.yaml
```

Use two separate `--exclude-group` flags: with a single comma-separated value this Pest/PHPUnit version stops excluding any group once one of the names matches no PHP test. More commands (coverage, contract only, Playwright, live tests): `docs/quality.md`. New behaviour comes with a test that fails without it.

### Regenerating the examples

The JSON examples in `packages/vehicle-data-core/resources/examples/` and the OpenAPI fragment `resources/openapi/examples.yaml` are rendered from a freshly seeded database, never hand-typed; the example blocks in the README and `docs/api.md` are copied from them and checked by `DocSnippetsTest`. After a change to the seed data, a serialisation or the contract, regenerate them and lint the assembled document:

```bash
docker compose exec -T app sh -c "php artisan migrate:fresh --seed --force && mkdir -p test-results && php artisan vehicle:examples render --openapi --openapi-out=test-results/assembled.yaml"
npm run lint:openapi:assembled
```

**`migrate:fresh` wipes the development database** (every table, including the API clients and keys you created): create a new key afterwards. `php artisan vehicle:examples render --check` verifies the committed files without writing; it too assumes a freshly seeded database. Then update any README or `docs/api.md` block that `DocSnippetsTest` (group `examples`) reports as different.

## Publishable commits

The repository is meant to be public, so **every commit must be publishable**:

- no secrets or API keys (gitleaks, with a rule for `vd_live_` keys);
- no names of consumer sites, companies, partners or clients, and no hosting hostnames, IPs, account names or local machine paths or user names (a hash-based blocklist: `scripts/blocklist.hashes.json` holds only SHA-256 hashes of forbidden tokens, so the words themselves never enter the repository; add one with `php scripts/blocklist.php add "<term>"`);
- no file over 100 KB (`composer.lock` and `package-lock.json` are exempt) and no images or other binaries.

`.githooks/pre-commit` enforces all three on staged content (gitleaks only when the binary is installed locally); CI runs the blocklist and gitleaks on every pull request, and the `release-check` job scans the full history again before a release. Never bypass the hook with `--no-verify`, and never rewrite published history to hide a mistake: fix it forward and rotate anything that leaked.

## Data policy

- Only the sources listed in `docs/data-sources.md`, each under a licence in the admitted list (`CC-BY-4.0`, `OGL-ROU-1.0`, `CC0-1.0`, `US-PD`); no share-alike data. `php artisan vehicle:sources check` enforces it.
- No scraping, no marketplace or pricing data, no hand-typed vehicle data. Every write goes through an importer, which records provenance per record.
- Fixtures (`packages/vehicle-data-core/database/fixtures/`, at most 200 rows each) are generated only by `scripts/fetch_fixtures.sh`; after regenerating them, run `php artisan vehicle:sources report` so `docs/data-sources.md` lists their checksums.
- Logos are referenced by Wikimedia Commons file name and licence only, never copied into the repository.

## Commits and pull requests

- Branch from `main`; `main` is protected and receives only squash merges of pull requests with green required checks (`lint`, `test (8.4, 10.5, false)`, `test (8.4, 10.11, false)`, `contract`, `build`, `browser / browser`, `deploy-dry-run`).
- Commit subjects follow `type(scope): subject` (`feat`, `fix`, `docs`, `chore`, `ci`, `test`, `refactor`); the pull request title becomes the squashed commit subject. Commits written with an AI assistant carry a `Co-Authored-By:` trailer naming it.
- Fill in `.github/PULL_REQUEST_TEMPLATE.md`: what changed, test output, and for API changes Spectral, oasdiff and CHANGELOG.
