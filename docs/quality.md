# Quality targets

What "good enough to release" means for this repository, how each target is enforced, and how to check it yourself. CI is `.github/workflows/ci.yml` (with `docs-e2e.yml` called as the `browser` job); the required checks on `main` are `lint`, `test (8.4, 10.5, false)`, `test (8.4, 10.11, false)`, `contract`, `build`, `browser / browser` and `deploy-dry-run`.

CI figures below were measured in this repository's CI. Results labelled "development repository" were measured before publication, in the private repository v1.0.0 was developed in; its history is not published (ADR 0006, addendum 2026-09-26).

## Targets and enforcement

| Target | Result at v1.0.0 | Enforced by |
|---|---|---|
| Test suite green | 496 tests, 3002 assertions (PHP 8.4; PHP 8.5 also runs, allowed to fail) - measured in CI at `891c516` (2026-09-26) | `test` job on MariaDB 10.5 and 10.11 |
| Line coverage ≥ 90 % of the package (`packages/vehicle-data-core/src` only) | 92.3 % - measured in CI at `891c516` (2026-09-26) (no coverage driver in the local Docker image, so this is not reproducible with `docker compose exec`; see "Running the checks locally" below) | `test` job: `pest --coverage --min=90` with pcov |
| PHPStan level 8, no baseline | 0 errors over `app/`, `packages/vehicle-data-core/src`, `packages/vehicle-data-core/database` | `lint` job |
| Pint (Laravel preset, strict types) clean | clean | `lint` job |
| Config caches (no closures in config) | passes | `lint` job: `config:cache` |
| OpenAPI document lints clean | Spectral clean, base file and the assembled document | `contract` job (the base file, then the assembled document - base + kinds + the rendered examples - with the example rules of `.spectral.yaml`), `browser` job (the document as served, with every kind) |
| Every response matches the contract | 51 contract tests (Spectator) - measured in CI at `891c516` (2026-09-26) | `contract` job: `pest --group=contract` |
| No breaking contract change without intent | oasdiff against the pull request's base branch, failing on errors | `contract` job on pull requests, unless the PR has the `breaking` label |
| Routes change only on purpose | route list equals `tests/snapshots/route-list.json` | `test` job |
| p95 < 150 ms on the seeded database (test suite, not a load test) | asserted for `/v1/makes/dacia/models`, `/v1/models/dacia-duster/variants?fuel=petrol`, `/v1/makes?sort=-ro_fleet_count` (20 requests each) | `PerformanceTest` in the `test` job |
| `/docs` renders from self-hosted assets only, search works, a keyed call succeeds; the four example clients run | Playwright, Chromium; `examples/` (`curl.sh`, `fetch.mjs`, `client.php`, `client.py`) against the served app | `browser / browser` job |
| No secrets in commits | gitleaks with a `vd_live_` key rule | pre-commit hook (when gitleaks is installed), `lint` job, `release-check` job (full history) |
| No consumer, company, client or host names | hash-based blocklist | pre-commit hook (staged content), `lint` job (working tree), `release_check.php` (working tree and full history) |
| No file > 100 KB (lock files exempt), no images or binaries | none tracked | pre-commit hook, `release_check.php` |
| No known vulnerable dependency | `composer audit` clean | `lint` job, `release_check.php` |
| Dependencies kept current | Dependabot weekly for Composer, npm and GitHub Actions, monthly for the Docker base image | `.github/dependabot.yml` |
| Release artefact builds and deploys | release tarball, Docker image, deploy with automatic rollback | `build` and `deploy-dry-run` jobs |
| Licences and fixtures documented | every source has an admitted licence and attribution; every fixture is listed with its checksum | `vehicle:sources check` in `release_check.php` |

## Running the checks locally

With the Docker stack up (README quick start). PHP runs in the `app` container; Node tooling on the host (Node 24) after `npm ci`.

```bash
# Unit, feature, contract, importer and seed tests (two separate --exclude-group flags)
docker compose exec -T app vendor/bin/pest --exclude-group=network --exclude-group=browser

# Contract tests only
docker compose exec -T app vendor/bin/pest --group=contract

# Static analysis and style
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G

# OpenAPI lint
npx spectral lint packages/vehicle-data-core/resources/openapi/openapi.yaml

# Browser test of /docs against the running stack (key: vehicle:client create --scopes=catalogue:read)
npx playwright install chromium
VD_TEST_KEY=<key> npx playwright test

# Dependency audit
docker compose exec -T app composer audit

# Blocklist over the working tree and the full history (host PHP is enough)
php scripts/blocklist.php scan .
php scripts/blocklist.php scan-history

# Live downloads from the real sources (opt-in; never in CI)
docker compose exec -T app vendor/bin/pest --group=network

# The whole release gate A
php scripts/release_check.php v1.0.0
```

Coverage needs a coverage driver, which the Docker image does not ship. With PHP 8.4 and pcov available (as in CI):

```bash
php -d pcov.directory=packages/vehicle-data-core/src vendor/bin/pest --exclude-group=network --exclude-group=browser --coverage --min=90
```

Use two separate `--exclude-group` flags: with one comma-separated value, this Pest/PHPUnit version stops excluding any of the groups once one name matches no PHP test (the `browser` group tags only the Playwright specs), so the network tests would run.

## Capacity

The measurements below predate ADR 0008. Warm client authentication now adds one indexed primary-database validity check, and the list query budget is 9 rather than 8. The old figures remain historical evidence; throughput has not been remeasured for this review.

Measured with `scripts/load.php` (closed-loop virtual users, integrator-like route mix) against a production-mode container on one Windows laptop, so a reader can reproduce it and knows it is a laptop-container figure, not a production promise. **Both columns below were measured with the identical procedure, on the same machine, on 2026-09-17** - the "Before" column is a fresh re-measurement of the development repository's `main` at `4102c33`, where the Phase 1 hardening started (a git worktree of that commit, `composer install`'d and containerised exactly like "After"), not the earlier ad hoc audit whose harness and container settings were never recorded. That older, unrecorded-harness figure is not used anywhere in this document any more.

One exception to "identical procedure": "After" was measured with the version of `scripts/load.php` before the idle-loop fix (`usleep(5000)` when `curl_multi_select()` returns `-1`, added afterward); "Before" was measured with the fixed version. The direction is conservative, not favourable to "After": a generator that busy-waits on `-1` steals CPU cycles from whatever it shares a host with, and "After"'s run is the one that ran *without* that fix - so if this had any effect at all, it would have modestly understated "After"'s numbers by leaving it slightly less CPU to work with, not inflated them.

Reproduce it with `scripts/perf-container.sh` (committed, host-neutral - no hostnames, ports or credentials are baked in). Its `IMAGE` (default `vehicle-data-api-app:latest`) and `NETWORK` (default `vehicle-data-api_default`) defaults assume `COMPOSE_PROJECT_NAME=vehicle-data-api`; if your `.env` sets a different `COMPOSE_PROJECT_NAME` (README quick start - a second checkout needs one), export matching `IMAGE=<project>-app:latest` and `NETWORK=<project>_default` before running the commands below:

```bash
# "After" (current tree): without --with-db, scripts/perf-container.sh does not
# migrate or seed anything - it shares whatever database the source directory's
# .env already points at. Running against the current tree from a fresh clone
# (not the already-migrated, already-seeded dev stack) needs that database
# migrated and seeded first: `php artisan migrate --force && php artisan
# db:seed --class="VehicleData\Core\Database\Seeders\ExampleDataSeeder" --force`.
bash scripts/perf-container.sh vd-perf 8089 .
docker exec vd-perf sh -c 'cd /var/www/vhosts/localhost/html && php artisan vehicle:client create --name=load --owner=perf --scopes=catalogue:read,vin:decode --origins=https://example.org --rate=60000 --quota=10000000'
docker cp scripts/load.php vd-perf:/tmp/load.php
export LOAD_KEY=vd_live_...   # the key printed above; a leading space keeps this line out of shell history on most shells
docker exec -e LOAD_KEY vd-perf php /tmp/load.php --base=http://localhost --users=<N> --seconds=60 --think=<min-max>
docker exec vd-perf sh -c 'cd /var/www/vhosts/localhost/html && php artisan vehicle:client revoke --id=<id>'   # the id printed by `create` above

# "Before" (any other commit of this repository; the table's `4102c33` exists
# only in the development repository): a git worktree, its own composer install, and
# --with-db so it gets a FRESH sibling database instead of the dev stack's
# already-migrated one (the schema at an older commit differs); --with-db also
# runs migrate/seed for you.
git worktree add /tmp/vd-base <commit>
cp /tmp/vd-base/.env.example /tmp/vd-base/.env
sed -i "s#^APP_KEY=.*#APP_KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')#" /tmp/vd-base/.env
docker run --rm --entrypoint composer -v /tmp/vd-base:/app -w /app vehicle-data-api-app:latest install --no-dev -q
bash scripts/perf-container.sh vd-base 8090 /tmp/vd-base --with-db
docker exec vd-base sh -c 'cd /var/www/vhosts/localhost/html && php artisan vehicle:client create --name=load --owner=perf --scopes=catalogue:read,vin:decode --origins=https://example.org --rate=60000 --quota=10000000'
docker cp scripts/load.php vd-base:/tmp/load.php
export LOAD_KEY=vd_live_...
docker exec -e LOAD_KEY vd-base php /tmp/load.php --base=http://localhost --users=<N> --seconds=60 --think=<min-max>
docker exec vd-base sh -c 'cd /var/www/vhosts/localhost/html && php artisan vehicle:client revoke --id=<id>'

docker rm -f vd-perf vd-base vd-base-db
git worktree remove /tmp/vd-base --force
```

Conditions common to both containers:

- Code copied into the image's own filesystem with `tar`, not the bind-mounted dev volume (the Windows-host bind mount is itself a major slowdown that a production deploy never has).
- `APP_ENV=production`, `APP_DEBUG=false`; `php artisan config:cache`, `route:cache` and `event:cache` run before the measurement.
- OPcache on with `opcache.validate_timestamps=0` (§3 of the deployment runbook) - the dev container instead runs with `validate_timestamps=1`, because its tree is the live bind mount.
- `docker create --cpus=2 --memory=2g`: 2 CPUs, 2 GB RAM, no other limit - for **both** the "Before" and "After" containers.
- MariaDB 10.5, reached over the Docker bridge network, not the host loopback. **"After" shares the dev stack's already-running, already-migrated database; "Before" gets its own fresh sibling** (`--with-db`), because `4102c33`'s migrations/schema predate several since-merged changes and can't safely share a database already migrated past them. This asymmetry is acceptable here because the two databases were seeded identically and counted equal before measuring (next bullet) - what matters for a fair comparison is the data each query touches, not which literal container serves it.
- Dataset: the repository's committed example fixtures - 20 manufacturers, 15 makes, 273 models, 180 variants (`ExampleDataSeeder`), counted directly in both databases before measuring; both came out identical.
- The load generator itself (`scripts/load.php`, copied in, never committed inside the measured tree) runs *inside* each production container over `localhost`, so the Windows host's network stack is not part of either measurement.
- The load client's `vehicle:client` has its rate/quota raised (`--rate=60000 --quota=10000000`) so the run measures serving capacity, not the per-client rate limit and quota (`ThrottleClient`, added in the Phase 1 hardening) - **the 429 path is not exercised by these numbers.**
- No compatibility tweak was needed for `4102c33` to accept a `vd_live_…` key or serve any of the ten routes in the mix - the key format and every route/slug used were already stable at that commit.
- **Attribution:** `4102c33` is the phase's starting `main`, not the base of the counters/client-cache change alone - everything merged since then (the first five Phase 1 hardening changes: hygiene, stable identifiers, the auth-fail-limiter fix, bounded retention, and the counters/client-cache change itself) sits between these two columns. The delta below is the combined effect of all of that, not of the counters/client-cache change alone.

| Scenario | Before (4102c33, re-measured) | After |
|---|---|---|
| 1 user, no think time | 18.1 rps, p50 50 ms, p95 88 ms | 22.2 rps, p50 37 ms, p95 88 ms |
| 10 users, no think time | 49.2 rps, p50 187 ms, p95 351 ms | 71.9 rps, p50 117 ms, p95 274 ms |
| 100 users, think 1–5 s | 32.6 rps, p50 67 ms, p95 350 ms | 32.8 rps, p50 52 ms, p95 378 ms |
| 100 users, think 0.2–1 s | 49.2 rps, p50 1.31 s, p95 2.48 s | 63.4 rps, p50 819 ms, p95 2.00 s |
| 100 users, no think time | 46.2 rps, p50 2.09 s, p95 3.22 s | 65.5 rps, p50 1.43 s, p95 2.29 s |

Errors: 0 in every run, before and after.

**What the numbers actually show.** Per-request latency (p50) improved at every concurrency level, including the think-1–5s scenario (67→52 ms, a real drop). What does *not* move there is throughput (32.6→32.8 rps) - and that exception is about rps specifically, not about latency: 100 users each idling 1–5s between requests offer only ~20–33 req/s of demand regardless of how fast the server answers, so a faster server can't push rps higher in that one scenario, only lower each response's own latency, which it does. The per-request latency win is consistent with `QueryBudgetTest`, which pins the *current* cost of a keyed list route at ≤9 queries with a warm client cache (8 before ADR 0008 added the authoritative credential check) - that test is a regression guard against the query count creeping back up, not evidence of the delta itself, since it was never run against `4102c33` and has no "before" number to compare.

Two different readings of the acceptance bar give two different answers, so both are stated rather than picking one: the absolute bar set for this phase (≥33 rps at 100 users/no-think - chosen as roughly double the unrecorded-harness figure this phase started from) **is met** - measured 65.5 rps. The stricter reading - 2× the honestly re-measured "Before" figure (2×46.2 = 92.4 rps) - **is not met**: 65.5 rps is +42%, not +100%. (An earlier draft of this section compared against an old, unrecorded-harness "16.6 rps" figure instead of the re-measured 46.2 rps; that comparison has been withdrawn, not reworded.) The reason aggregate throughput doesn't double even though per-request latency clearly dropped: at 100 concurrent users this laptop's 2-CPU container is saturated (`docker stats` read ~168% of the 2-CPU cap mid-run, both before and after) - that CPU ceiling, not the rate-limit/cache mechanism the counters/client-cache change touched, is what caps aggregate rps once concurrency is high enough, and it caps both versions equally. No further tuning was attempted for this bar; a next step would be measuring on a host with more CPU headroom, or reducing the per-request query count further below the ≤9-query budget `QueryBudgetTest` already pins.

The in-CI `PerformanceTest` (p95 < 150 ms over 20 sequential requests per route) is a regression guard for single-request cost, not a concurrency test. `make load` runs the same script against the **dev stack** (bind-mounted code, `opcache.validate_timestamps=1`) as a quick smoke check that the load generator and a key still work end to end - its numbers are not comparable to the table above; use `scripts/perf-container.sh` for anything you want to compare against it. The key is read from the `LOAD_KEY` environment variable, never a `--key=...` argument (that would land in this process's argv, visible to anyone who can run `ps` while it's running): `export LOAD_KEY=vd_live_…` first - prefix that line with a space if your shell's `HISTCONTROL` includes `ignorespace`, to keep it out of shell history too - and never paste a live key into a shared or recorded terminal.
