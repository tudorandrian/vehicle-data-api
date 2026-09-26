# Engineering notes

vehicle-data-api is a read-only, OpenAPI-first vehicle catalogue API on openly licensed public data (makes, models, variants, manufacturers, bilingual taxonomies), built as a portfolio project for anyone assessing the author's engineering practice — hiring reviewers, other engineers evaluating the code, and integrators deciding whether to build on it. It was built in two phases with AI assistance (Claude Code) and reviewed and released by the author.

## Decisions and what they cost

| ADR | Decision | Cost accepted |
|---|---|---|
| [0001](adr/0001-stack.md) | Laravel 13 on PHP 8.4, MariaDB 10.5 with 10.11 as a second required CI leg and the supported baseline (amended 2026-09-26), OpenLiteSpeed — matches the shared-hosting target | No Redis or warm worker; performance leans on indexes and ETags, with p95 asserted in tests |
| [0002](adr/0002-public-core-private-platform.md) | Domain logic lives in an in-repo package (`packages/vehicle-data-core`); a private platform extends it as a pinned Git submodule | The core's contract signatures are load-bearing for an external repository — changing one is a breaking change for the platform, and the core must stay free of private names and data |
| [0003](adr/0003-openapi-3-0-3.md) | OpenAPI 3.0.3 with `nullable: true`, not 3.1 | A later migration to 3.1 is mechanical but not yet possible: the PHP response validators in use only parse 3.0.x |
| [0004](adr/0004-field-classes-and-evolution.md) | Three field classes (never null, always present, omitted when absent), enforced by contract tests | Additive changes only in `/v1`; anything breaking a class or a shape needs a new major version and deprecation headers |
| [0005](adr/0005-open-data-and-licences.md) | Only four sources, each under an admitted, non-share-alike licence | Coverage is limited to what those sources publish — no `body_type`, `gearbox`, `drive` or `colour` data yet |
| [0006](adr/0006-private-first-release.md) | Every commit is publishable from the first one (blocklist, gitleaks, three release gates). Amended 2026-09-26: v1.0.0 is published as a new public repository that starts from a single-commit snapshot of the reviewed tree, checked by the gates before it is pushed; the development history stays private | No history rewrite in this repository from its first commit: whatever a gate finds is fixed forward, and only secrets are rotated |
| [0007](adr/0007-stable-identifiers.md) | Immutable `id` per record; `slug` may change, with a 301 alias | Import identity resolution is provenance-first with explicit collision rejection; the Phase 2 addendum adds deterministic first minting so a fresh seed reproduces the documented ids |
| [0008](adr/0008-authoritative-credential-validation.md) | Cached client metadata is checked against the primary database before reuse | One extra indexed query on every warm authenticated request; the list-route query budget moved from 8 to 9 |
| [0009](adr/0009-taxonomy-public-identifiers-and-renames.md) | Stable public ids for taxonomies and terms, with retired-key aliasing | A little more storage, and renames must go through the dedicated `TaxonomyIdentity` path rather than an edited row |

## Numbers and where they come from

All measured in CI of the development repository at `a55c643` (2026-09-25), before publication; the release replaces them with figures from this repository's CI ([docs/quality.md](quality.md)): 433 tests, 2,605 assertions (PHP 8.4; PHP 8.5 also runs, allowed to fail); 51 contract tests (Spectator); line coverage 92.1 % of `packages/vehicle-data-core/src` (90 % floor). The p95 guard (`PerformanceTest`) asserts p95 < 150 ms over 20 sequential requests each on `/v1/makes/dacia/models`, `/v1/models/dacia-duster/variants?fuel=petrol` and `/v1/makes?sort=-ro_fleet_count` — a regression guard on the seeded test database, not a load test.

Capacity ([docs/quality.md#capacity](quality.md#capacity)): 100 concurrent users, no think time, on a 2-CPU/2GB laptop container — 65.5 rps after the first five Phase 1 hardening changes (hygiene, stable identifiers, the auth-fail-limiter fix, bounded retention, counters/client-cache), against 46.2 rps re-measured on the development repository's `main` at `4102c33`, where the hardening started, with the identical procedure. 0 errors in every run, before and after. Measured 2026-09-17, before ADR 0008's extra auth query; not re-measured. This is a laptop-container figure, not a production promise; the CPU cap, not the change under test, is what limits aggregate throughput at that concurrency.

The seven required checks (`.github/workflows/ci.yml`, `docs/quality.md`): `lint`, `test (8.4, 10.5, false)`, `test (8.4, 10.11, false)`, `contract`, `build`, `browser / browser`, `deploy-dry-run`.

## What is deliberately not built

- **A hosted public demo.** The public core is complete and deployable on its own, but "this repository itself is not operated as a public service" (README "Tiers"); operating it is the private platform's job.
- **`/v2`.** Nothing has shipped a breaking change yet: additive changes stay in `/v1` (ADR 0004), so there is no second major version to serve.
- **Redis.** The shared-hosting target has no Redis (ADR 0001); rate limits, quotas and failed-authentication counters are SQL upsert counters on the primary database instead — atomic increments that avoid lost updates, at the stated cost that "authentication failures still use database resources; edge limits remain necessary" (architecture review, "Decisions worth retaining").
- **POST flows.** Every route is read-only by design (`docs/api.md`); there is no write endpoint and no idempotency key anywhere in the contract.
- **A telemetry sanitiser.** The architecture review found Sentry's default-PII setting does not scrub URLs, headers or exception text (R1); no scrubber has been built, so `SENTRY_LARAVEL_DSN` stays unset ([docs/known-limitations.md](known-limitations.md)).
- **A second vehicle kind.** `VariantWriter`/`FleetWriter` hard-code the `car` kind at v1.0.0; a registered second kind gets a schema and a contract fragment but no core writer can import it yet (R10, [docs/known-limitations.md](known-limitations.md)).

## Limitations

Twelve open findings from the architecture review carry an explicit v1.0.0 decision, fixed or accepted with a stated reason and a workaround: [docs/known-limitations.md](known-limitations.md).

## How it was reviewed

Three release gates ([docs/release-gate.md](release-gate.md)): A is mechanical (`scripts/release_check.php`, 9 checks); B is a blind review by readers who see only the public files; C is the owner's own fresh-clone run and confirmation. Gate B recorded 18 findings, 16 fixed or accepted with a stated reason and two still open for the owner (the blocklist terms and the OGL-ROU attribution wording).

The Phase 1 hardening ran as 13 independently reviewed tasks, closed by a final review whose findings were fixed within that phase. The [2026-09-20 architecture review](architecture-review.md) then read the whole codebase and raised 12 further findings (R1–R12), each since given a recorded decision.

Phase 2 (this public-launch work) is also 13 tasks, each with an independent review; 9 of the 13 needed fix rounds, 11 rounds in total so far. A final multi-reviewer review of the whole branch runs after this, the last Phase 2 feature pull request, and before the v1.0.0 release — it has not run yet, and this document makes no claim about its outcome.
