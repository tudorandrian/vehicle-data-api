# Bootstrapping the private platform

How the private extension (`vehicle-data-platform`) is built on top of this public core without forking it (ADR 0002). This is the input for the platform's own plan. Do it only after the core has a release tag: the platform pins the core at a tag in three places (submodule, package path, deploy workflow), and before the first tag all three would still be moving.

Placeholders in angle brackets are filled in the private repository only; none of their values belong in this repository.

## 1. Create the repository

Create `tudorandrian/vehicle-data-platform` as a **private** repository, with branch protection on `main` (pull requests, squash merges, required checks). Being private, it may name its consumers and hosts; secrets still never go into commits, and gitleaks runs in its CI as in the core's.

## 2. Pin the core as a submodule at a tag

```bash
git submodule add -b main https://github.com/tudorandrian/vehicle-data-api upstream
cd upstream && git checkout v1.0.0 && cd ..
git add .gitmodules upstream && git commit -m "chore: pin vehicle-data-api v1.0.0"
```

The submodule records the exact commit of the tag; `-b main` only sets the branch that `git submodule update --remote` would follow, which is never used for releases.

## 3. Require the core package from the submodule

The platform is a Laravel 13 application whose root `composer.json` requires the core package by path:

```json
{
  "repositories": [
    { "type": "path", "url": "upstream/packages/vehicle-data-core", "options": { "symlink": true } }
  ],
  "require": {
    "php": "^8.4",
    "laravel/framework": "^13.17",
    "tudorandrian/vehicle-data-core": "*@dev"
  }
}
```

`CoreServiceProvider` is auto-discovered, so migrations (`vd_` tables), routes (`/v1/*`, `/openapi.yaml`), commands and config arrive with the package. Copy from the core application what the package does not contain: `bootstrap/app.php` (middleware groups `core` and `data`, priorities, `ProblemRenderer`), `routes/web.php` and `DocsController` with `resources/views/docs.blade.php` and `public/js/docs.js` for `/docs`, `routes/console.php` (the schedule), `config/logging.php` (JSON daily logs) and `config/sentry.php`, `package.json` with the Scalar bundle `postinstall`, and `.env.example`.

## 4. The private package and its service provider

Create `packages/vehicle-data-private` (namespace of your choice, path repository like the core) with a `PrivateServiceProvider` that uses the four extension contracts from `VehicleData\Core\Contracts` (`RecordWriter` and `RunAware`, also in that namespace, are internal to the import pipeline, not extension points):

```php
public function register(): void
{
    // Enrichers: tagged, applied to every serialised record; may only add class-3 keys.
    $this->app->bind(LogoMirrorEnricher::class);
    $this->app->tag([LogoMirrorEnricher::class], 'core.enrichers');

    // Data sources: SourceRegistry is immutable, so rebind it with the core's sources plus the private ones.
    $this->app->extend(SourceRegistry::class, fn (SourceRegistry $core) => new SourceRegistry(
        array_combine($core->keys(), array_map($core->get(...), $core->keys())) + ['curated-facets' => new CuratedFacetsSource],
    ));

    // Kinds: a SpecificationSchema per kind; its OpenAPI fragment is assembled into /openapi.yaml.
    $this->app->extend(KindRegistry::class, function (KindRegistry $registry) {
        $registry->register(new <Kind>SpecificationSchema);

        return $registry;
    });

    // Optional: a different key store (for example one shared with another system).
    // $this->app->bind(ClientResolver::class, <PlatformClientResolver>::class);
}
```

Check the registry APIs at the pinned tag (`src/Importers/SourceRegistry.php`, `src/Kinds/KindRegistry.php`, `src/Importers/ImportPipeline.php`) before writing this. At v1.0.0 the import pipeline's writers are fixed in `CoreServiceProvider` (`variant`, `fleet`, `manufacturer`, `wmi`): a private source either maps its rows to one of those types or needs a core pull request that lets extensions register a `RecordWriter`. Extend the core through pull requests rather than reaching into its internals.

## 5. First implementations

- **First `Enricher`: logo mirroring.** Copy manufacturer logos referenced in the core (`logo.commons_file`, `logo.licence`) to the platform's own storage only when the Commons licence allows it (public domain, CC0, CC BY without SA), keep the attribution, and expose the copy as a class-3 key such as `logo.mirror_url`. Never overwrite class-1 or class-2 keys.
- **First `DataSource`: licensed facets.** Populate the taxonomies the core defines but does not source (`body_type`, `gearbox`, `drive`) only after identifying and documenting an admitted source and its provenance. Do not hand-type vehicle facts or treat private repository visibility as permission to bypass the source policy. Fields with no admitted source remain unknown. Any broader private-data policy requires its own explicit review and must keep those records out of the public catalogue and fixtures.

## 6. OpenAPI

Nothing to copy by hand for kinds: every registered `SpecificationSchema` contributes its `openApiFragment()` as `components.schemas.<Kind>Specifications` when `/openapi.yaml` is assembled. New private routes, if any, need their own paths in a platform-level document and their own contract tests.

## 7. Deploy with the core's reusable workflow

```yaml
# .github/workflows/deploy.yml in the platform
name: deploy
on:
  push: { tags: ['v*'] }
  workflow_dispatch:
jobs:
  deploy:
    uses: tudorandrian/vehicle-data-api/.github/workflows/deploy.yml@v1.0.0
    with:
      app-path: "."
    secrets: inherit
```

The called workflow checks out the calling repository with submodules, installs Composer and npm dependencies, builds the release tarball with `scripts/build_release.sh` and runs `scripts/deploy.sh` over SSH, relative to `app-path`; the platform therefore needs those two scripts at that path (copy them from the pinned tag). The core's `ci.yml` jobs (lint, tests, contract, build, deploy dry run) are the model for the platform's own CI.

## 8. Secrets

Repository or `production` environment secrets in the platform (never in the core): `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` (a dedicated deploy key), `DEPLOY_KNOWN_HOSTS` (pinned host key from `ssh-keyscan`), `DEPLOY_TARGET`, `DEPLOY_PHP_BIN`, `DEPLOY_SECRET` (maintenance bypass token), `DEPLOY_SMOKE_URL`, `DEPLOY_SMOKE_KEY` (a `catalogue:read`-only client). Their meaning: `docs/deployment-cpanel.md` §6. Server-side values (`APP_KEY`, database credentials, `CORE_TRUSTED_PROXIES`, `CORE_DOCS_TRY_IT_KEY`, `SENTRY_LARAVEL_DSN`) live only in the server's `shared/.env`.

## 9. Go-live checklist

Following `docs/deployment-cpanel.md`:

- [ ] API subdomain created, document root `~/apps/platform/current/public`
- [ ] PHP 8.4 (`ea-php84`) selected for the subdomain in MultiPHP Manager
- [ ] `shared/.env` created with production values, `APP_DEBUG=false`
- [ ] the single `schedule:run` cron line added
- [ ] first release deployed by hand (runbook §5), then by tag through the workflow
- [ ] yearly imports run once (`eea --year`, `ro-fleet --year`), then `wikidata`, `wmi`
- [ ] clients created: one per consumer, narrow scopes and origins; a separate `catalogue:read`-only demo key for `/docs` and a smoke key for the deploy workflow
- [ ] CDN/WAF proxy on, TLS full (strict), cache bypass for requests with `Authorization`
- [ ] external monitor on `/v1/health`; Sentry remains disabled until outbound event and trace sanitization is verified (see SECURITY.md)
- [ ] `/docs` opened on the live host; one keyed call from the consumer's origin succeeds and a foreign origin gets `403`

## 10. Upgrading the core

```bash
cd upstream && git fetch --tags && git checkout v1.1.0 && cd ..
```

1. Read the core's `CHANGELOG.md` between the two tags.
2. Compare the contracts of the two tags with oasdiff (`git -C upstream show v1.0.0:packages/vehicle-data-core/resources/openapi/openapi.yaml > /tmp/old.yaml`, then `oasdiff breaking /tmp/old.yaml upstream/packages/vehicle-data-core/resources/openapi/openapi.yaml`). A minor release of the core must report no breaking change.
3. Bump the submodule and the `deploy.yml@<tag>` reference in the same pull request, run the platform's CI (including its contract tests with the private kinds registered), and note the upgrade in the platform's CHANGELOG.
4. Deploy by tag; `scripts/deploy.sh` runs the new additive migrations and rolls back automatically if the smoke check fails.
