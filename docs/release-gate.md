# Release gate

A version is tagged only when all three gates pass (ADR 0006). Gate A is mechanical, gate B is a blind review by readers who see only the public files, gate C is the owner's own run and confirmation. This page records the gates for **v1.0.0**, the deviations from the design that were accepted on the way, and what is still open.

Built with AI assistance (Claude Code); reviewed and released by the author.

**Status: v1.0.1 released on 2026-09-26** (patch: sample-data notices, S1–S9 in [known limitations](known-limitations.md), the zero range-bound fix and the `/docs` build-graph update). v1.0.0 was released the same day, published as this public repository (first commit `e3e8126`), with the tag `v1.0.0` and a GitHub release. Remaining owner items are listed under "Open items for the owner".

The [2026-09-20 architecture review](architecture-review.md) identifies additional open launch risks, especially telemetry privacy, database support and deployment gating. Earlier checked items below are historical evidence, not approval of those new findings. Each finding's decision is recorded in [docs/known-limitations.md](known-limitations.md); rerun the gates on the release commit.

## Who must find what (the readers the gates replay)

| Reader | Question | Where the answer is |
|---|---|---|
| Anyone curious | What is this and can I try it? | README first screen: one sentence, the `/docs` description, the quick start, and "The data in seven calls" with Romanian labels |
| Journalist or data user | Where does the data come from, may I reuse it? | README "Data and licences", `docs/data-sources.md`, `sources[]` in every single-record response, `X-Data-Attribution` on lists and CSV, `X-Data-Licences` on snapshots |
| HR specialist | Which skills does this prove, is it finished? | README "What this demonstrates", `CHANGELOG.md` `[1.0.0]`, the CI badge, `docs/quality.md`, [docs/engineering-notes.md](engineering-notes.md) |
| Technical specialist | Is the code good, would I trust it? | `openapi.yaml`, `docs/api.md`, `docs/quality.md` (PHPStan level 8 without baseline, 92.3 % coverage measured in CI at `891c516` (2026-09-26), contract tests on MariaDB), ADRs 0001–0009, `packages/vehicle-data-core/src/Contracts/`, [docs/known-limitations.md](known-limitations.md), [docs/engineering-notes.md](engineering-notes.md) |
| Decision maker | What does it cost to run, what is the risk? | `docs/deployment-cpanel.md` (shared hosting, one cron line, no Redis), `SECURITY.md`, README "Tiers" and "Data and licences", this page |
| Integrator (for example a website) | How do I get a key and call it from my site? | README "Getting a key", `docs/api.md` (CORS, rate limits, caching, errors), `/docs` "Try it" |
| AI assistant | What are the rules for extending it safely? | `AGENTS.md` → `CONTRIBUTING.md` (field classes, evolution rules, the four extension contracts), the OpenAPI document |

## A. Mechanical

`php scripts/release_check.php vX.Y.Z` must print `[x]` on every line, except that on a workstation before the release pull request sets the date, the CHANGELOG line is the `[?] … (manual: date not set)` line. It runs on a workstation (artisan and Composer inside the Docker `app` container; gitleaks as a local binary or the pinned `zricethezav/gitleaks:v8.30.1` image) and again in CI as the `release-check` job on the tag (`--direct`, with a checksum-verified gitleaks binary).

- [x] blocklist clean over the working tree and the full history (for the terms it holds; see open item 1)
- [x] gitleaks clean over the full history
- [x] no tracked file > 100 KB (`composer.lock` and `package-lock.json` exempt), no image or binary
- [x] every source has an admitted licence, licence URL and attribution; every fixture file is named in `docs/data-sources.md`
- [x] CHANGELOG has `[1.0.0]` (dated `## [1.0.0] — YYYY-MM-DD` on the tag run; on a workstation an undated heading is reported `[?] … (manual: date not set)`, because the release pull request adds the date after gate A)
- [x] latest `ci` run on `main` succeeded
- [x] `composer audit` clean
- [ ] after tagging: the `release-check` job is green on the `v1.0.0` tag run

Output in the development repository, on the release branch, after the gate B fixes:

```
Release check for v1.0.0
[x] blocklist: working tree clean
[x] blocklist: full history clean
[x] gitleaks: full history clean — 62 commits scanned (pinned zricethezav/gitleaks:v8.30.1)
[x] no tracked file > 100 KB (lock files exempt)
[x] no tracked image or binary file
[x] sources: licence + attribution + fixtures documented — Sources: 4 with an admitted licence, licence URL and attribution; fixtures: 4 named in the data-sources document.
[x] CHANGELOG has [1.0.0]
[x] CI green on main — latest conclusion: success
[x] composer audit clean — No security vulnerability advisories found.
PASS: 9 checks, 0 manual
```

The "62 commits scanned" figure is not `main`'s commit count: `gitleaks git` with no `--log-opts` scans every local ref/branch present in the checkout, not just `main`'s ancestry — confirmed by running it against the development repository: `git rev-list --count HEAD` is 28 on `main` alone, but `git rev-list --count --all` and gitleaks's own count both land in the mid-50s once the checkout's other local branches are counted too. So this figure moves with whichever stray local branches happened to exist in that particular checkout, not with how many commits have landed on `main` — see the gate log entry below for why a later run can report fewer.

Re-run on host PHP after the release-review fix wave (`scan-history` now also covers commit messages, author/committer identities and ref names — see the "Accepted deviations" and open item 1 below): same result, 62 commits scanned.

The `release-check` job was also exercised once on the development repository's release pull request through a temporary trigger (all 9 lines `[x]`).

Result: **pass**, provisional until open item 1 is done and the job passes on the tag · date: 2026-09-17.

## B. Blind review

Brief for the auditor, who received only `README.md`, `docs/api.md`, `packages/vehicle-data-core/resources/openapi/openapi.yaml` and the output of `git ls-files`:

> You are three readers: an HR specialist, a senior PHP engineer, and a data-licensing officer. For each, answer the question in the table above using only these files, then list anything that identifies a company, client, hosting provider or consumer website, and anything you could not find.

All three reader questions were answered from the README within about thirty seconds; no broken links; no client, consumer-site, person or machine identifiers were found.

| # | Finding | Reader | Status | Fixed in, or accepted because |
|---|---|---|---|---|
| F1 | Four sensitive term types (API subdomain, hosting hostname, server IP, hosting account name) were never added to the blocklist, so history was never scanned for them | all | **open (owner)** | Only the owner knows the terms; open item 1 |
| F2 | The MIT `LICENSE` has no carve-out for the committed fixture data | licensing | fixed | `packages/vehicle-data-core/database/fixtures/LICENSE.md` (source, licence, attribution per file), `NOTICE`, README "Licence"; the MIT text is unchanged |
| F3 | `public, s-maxage=3600` on keyed taxonomy and snapshot responses would let shared caches serve them without a key | engineer | fixed | Every keyed response is `private`; `Vary: Authorization, Origin` on all keyed responses; tests, contract and `docs/api.md` "Caching" |
| F4 | Lists, CSV and snapshots carry no attribution; the snapshot `Link` points at a mutable `main` URL | licensing | fixed (attribution); accepted (link) | `X-Data-Attribution` on JSON lists, CSV and snapshots, exposed to browsers, in the contract and `docs/api.md`. The `main` link stays: v1.0.0 is published under this repository name and `docs/data-sources.md` is the maintained document |
| F5 | Logo schema has no author, and `licence` is nullable despite the admitted-licence rule | licensing | fixed (licence); accepted (author) | `logo.licence` is non-null in code and contract (the logo object is omitted without a licence). No author field: logos are referenced by Commons file name and licence only (ADR 0005); the Commons page at `logo.url` carries the author credit, as `docs/api.md` states |
| F6 | The OGL-ROU English attribution omits "Romania" and mirrors the UK OGL wording | licensing | verified; wording decision **open (owner)** | See "OGL-ROU attribution wording" below and open item 5 |
| F7 | Contract and docs disagree: `q` on variants, `updated_since` date vs date-time, 405/413/500 missing, `request_id` nullable, 403 members missing, snapshot 406 and `Cache-Control` | engineer | fixed | Code behaviour kept and documented: `q` is not a variants parameter (docs corrected); `updated_since` accepts a date or date-time (contract); `413` and `500` declared on every operation and `405` in the description; `request_id` non-null; `required_scope`/`origin` in a `ForbiddenProblem` schema; snapshots ignore `Accept` (no `406`, documented) and declare their headers; new contract assertions for 403 members and 413 |
| F8 | Showcase example "Dacia Duster 1199 cm³ 12 kW" is implausible; "Fiat Fiat 500" repeats the make | engineer, licensing | fixed | `docs/api.md` example now a diesel Duster (1461 cm³, 84 kW); "Known data issues" in `docs/data-sources.md` (source values passed through unmodified); the coverage table no longer repeats a make already in the model name (wording only, data unchanged) |
| F9 | Planning identifiers and stale notes in code comments | engineer | fixed | Comments across `src/`, tests, workflows and scripts rewritten as plain rationale; stale notes removed |
| F10 | 1.0.0 is presented as released while gates are pending and no tag exists | HR | accepted | The tag followed the owner's approval on 2026-09-26; until then this page stated that the release was not tagged |
| F11 | This page contained agent wording and internal working notes | HR | fixed | Rewritten: gate results, findings, deviations and owner items only |
| F12 | README lacks an authorship and purpose line | HR | fixed | README second paragraph |
| F13 | `docs/data-sources.md` counts looked inconsistent (rows read vs file rows, checksum "-") | engineer | fixed | Each source now states what "rows read" counts (aggregated make/model totals, merged Wikidata items); the imported-file checksum is labelled "not recorded" where the source does not record one; fixture rows are "rows in the file" |
| F14 | Coverage is package-only, p95 comes from 20 requests, "four contracts" overstated | engineer, HR | fixed | Qualified in README, `docs/quality.md` and CONTRIBUTING |
| F15 | `docs/deployment-cpanel.md` refers to "the spec", "this change" and "the codebase's conventions" | engineer | fixed | References to `config/sentry.php`, CONTRIBUTING and ADR 0004 instead |
| F16 | Example VIN may belong to a real vehicle | licensing | fixed | README and `docs/api.md` use the synthetic `WVWZZZ3CZWE000001` (same WMI and decode; tests keep their own) |
| F17 | Nothing on combined-output licensing, database rights, "changes made" or warranty | licensing | fixed | Sentences in README "Data and licences" and `docs/data-sources.md` |
| F18 | Cloudflare named throughout the deployment runbook | all | fixed | Named once as an example of a CDN/WAF; generic elsewhere |
| — | `docs/platform-bootstrap.md` names the planned private repository | engineer | accepted | It is the author's own planned repository |

Result: **passed with F1 open (owner)** · date: 2026-09-17 · by: independent auditor; fixes verified by re-running gate A.

## C. Owner

- [x] Fresh clone into a clean directory; the README quick-start commands run and succeed
- [x] `GET http://localhost:8087/v1/health` returns `200`, and `http://localhost:8087/docs` returns `200` and opens with the reference rendered and search working
- [x] With the key created in the quick start, the seven calls of README "The data in seven calls" return `200` with the ids the README shows, the by-id call (`GET /v1/makes/<the id of dacia>`) returns `200` with the same record, and the deliberate failure (`GET /v1/makes?per_page=500`) returns `422`
- [x] A catalogue call without a key returns `401`
- [x] `VD_API_KEY=<that key> bash examples/curl.sh` ends with `OK: 10 calls, last id <26-character id>`
- [x] Nothing in the repository identifies the consumer site, the company or the host: **yes** (the owner searched the published tree for the hosting terms on 2026-09-26: no match)
- [x] After publication (step 4 of the release procedure, once the first `ci` run is green): the steps above repeated from a fresh clone of the **public** repository (`git clone https://github.com/tudorandrian/vehicle-data-api.git`) into a new directory, with non-default settings in its `.env` before `docker compose up` — a distinct `COMPOSE_PROJECT_NAME` and distinct `VD_HTTP_PORT`, `VD_DB_PORT`, `VD_MAILPIT_SMTP_PORT` and `VD_MAILPIT_UI_PORT` — so it cannot reuse another checkout's containers or ports; use the chosen HTTP port in place of `8087` in the URLs and pass it to the example as `VD_BASE_URL=http://localhost:<port>`. Tear it down with `docker compose down -v` and delete the directory afterwards

A dry run of the gate C steps as they stood then (three README example calls) from a fresh clone of the release branch on 2026-09-17 succeeded: health `200`, `/docs` rendered from the self-hosted bundle, the three example calls `200` with Romanian and English labels, `401` without a key. On Windows, the README notes the long-path setting and the Git Bash form of the Scalar command.

Public-clone run on 2026-09-26 (fresh clone of this repository at `e3e8126`, `COMPOSE_PROJECT_NAME=vdagatec`, ports 8187/3414/1132/8132, run by Claude Code for the owner): the quick start succeeded as written; health `200`; `/docs` `200` with the self-hosted Scalar bundle `200` (search is exercised by the `browser` job); the seven README calls `200` with the README's ids, compared programmatically; the by-id call `200` with the same record; `per_page=500` `422`; `401` without a key; `examples/curl.sh` ended `OK: 10 calls`, and the JavaScript, PHP and Python clients also passed. Torn down with `docker compose down -v` and the directory deleted.

Result: **pass** · date: 2026-09-26 · confirmed by the owner's approval of the release.

## Accepted deviations from the design

Each was verified against the code at this release.

| # | Area | What v1.0.0 does instead of the design | Why accepted |
|---|---|---|---|
| 1 | Contract version | OpenAPI 3.0.3 with `nullable: true`, not 3.1 | PHP response validators parse only 3.0.x (ADR 0003) |
| 2 | EEA import | Reads the Discodata SQL endpoint in JSON pages of 1000 rows (`EeaSource`), not a CSV download | Memory stays flat and no whole-dataset file has to be downloaded |
| 3 | Daily quota | A cache counter per client and UTC day (`ThrottleClient`, on the database cache store) | One atomic increment per request; usage rows are aggregated separately by `vehicle:usage aggregate` |
| 4 | Breaking-change check | oasdiff compares against the pull request's base branch (skipped with the `breaking` label), not against the last release | Catches the break in the pull request that introduces it; before v1.0.0 there was no release to compare with |
| 5 | ETag | Only on buffered `200` responses: JSON representations and `/openapi.yaml`; none on CSV, snapshots, or errors returned before the controller runs | Streamed bodies cannot be hashed without buffering them; errors must not be revalidated |
| 6 | Envelope | Taxonomy routes return `data` and `meta` only, without `links` and `sources` | Taxonomies are unpaginated project vocabularies, not imported records |
| 7 | Browser test | Playwright checks rendering, search and self-hosting on `/docs`, and makes the keyed call with `request.get` rather than through the Scalar "Try it" panel | The panel's markup changes between Scalar releases; the keyed call is what is under test |
| 8 | Snapshots | A gzip file (`Content-Type: application/gzip`, no `Content-Encoding`) with `X-Data-Licences` and `Link: rel="license"` | A transparently decoding client would otherwise save plain JSON Lines under a `.gz` name; licence information travels with bulk data |
| 9 | Fixtures | Generated by `scripts/fetch_fixtures.sh` with deterministic per-make quotas | Reproducible and diverse (no single make dominates); checksums in `docs/data-sources.md` |
| 10 | Sentry variable | `SENTRY_LARAVEL_DSN`, not `SENTRY_DSN` | The name the Sentry Laravel SDK reads (docs/deployment-cpanel.md §3) |
| 11 | README image | `/docs` is described in words; no screenshot | The no-images rule of the publishable-commit policy wins |
| 12 | Import atomicity | `ImportPipeline::run()` wraps an entire source run in one transaction, not one transaction per 1,000-row batch as the design describes (`ImportPipeline::BATCH` still controls how many rows are held in memory and flushed at a time) | An aborted or failed run (reject-share exceeded, an exception) must leave no partial writes; committing per batch would let an aborted run keep the batches it already flushed |

## OGL-ROU attribution wording

The official licence text (https://data.gov.ro/base/images/logoinst/OGL-ROU-1.0.pdf, Romanian only; title "Licența pentru o Guvernare Deschisă") requires, when the publisher gives no attribution text or too many items are used, the statement:

> Conține informații publice în baza Licenței pentru Guvernare Deschisă v1.0

The project currently uses "Conține informații publice sub Licența Guvernamentală Deschisă v1.0" (Romanian) and "Contains public information under the Open Government Licence v1.0" (English, the project's own translation; the licence has no official English text). The Romanian wording differs from the official statement, and the English one follows the UK Open Government Licence phrasing without naming Romania. The code is unchanged because the wording comes from the design; which statement to serve was an owner decision (open item 5): v1.0.0 keeps the current wording.

## Open items for the owner

Status on 2026-09-26: gates B and C pass for v1.0.0; gate A is the `release-check` job on the tag run, recorded in the gate log. Items 1 and 5 were closed by the owner's decisions below; item 1's hash update and item 2 remain.

1. **F1, blocklist terms.** Closed for v1.0.0 on 2026-09-26: the owner searched the published tree (one commit, so also its history) for the API subdomain, the hosting hostname, the server IP and the hosting account name, with no match. Still to do, so that CI guards them from now on: add the terms with `php scripts/blocklist.php add "<term>"` and commit the updated hashes through a pull request.
2. **Design amendment.** The design document's decision D3 (OpenAPI 3.1) still needs the amendment recording ADR 0003; it lives outside this repository.
3. **Gate C.** Done 2026-09-26 (section C above).
4. **Approval** of the `v1.0.0` tag and GitHub release and the publication as a new public repository: given 2026-09-26. The profile updates that follow remain.
5. **F6, OGL-ROU wording.** Decided 2026-09-26: v1.0.0 keeps the current wording. Serving the official statement remains possible in a later release (update `Licence::oglRou()`, regenerate `docs/data-sources.md`, re-run gate A).
6. Private vulnerability reporting, which `SECURITY.md` points reporters to: enabled 2026-09-26 (step 4 of the release procedure below).

## Gate log

Append-only record of gate runs. Each entry is a single pass through gate A (mechanical) and, where noted, a gate C dry run (fresh clone). Entries dated before this repository's first commit record runs in the development repository: the SHAs, commit counts and CI results they cite belong to its history, which is not published (ADR 0006, addendum 2026-09-26).

### 2026-09-18 — Phase 1 hardening close-out

- **Development repository `main` at** `07011dd9e0b8c134133335c41548e59f47cfd7bf` (the Phase 1 hardening: hygiene and publishable-commit gate, stable identifiers, the auth-fail-limiter fix, bounded retention, counters/resolved-client cache and measured capacity, `vehicle:client create` option validation). Latest `ci` run on `main` for this SHA: `success`.
- **Gate A** (`php scripts/release_check.php v1.0.0`, run on host PHP — see finding below): all 9 lines `[x]`.
  ```
  Release check for v1.0.0
  [x] blocklist: working tree clean
  [x] blocklist: full history clean
  [x] gitleaks: full history clean — 52 commits scanned (pinned zricethezav/gitleaks:v8.30.1)
  [x] no tracked file > 100 KB (lock files exempt)
  [x] no tracked image or binary file
  [x] sources: licence + attribution + fixtures documented — Sources: 4 with an admitted licence, licence URL and attribution; fixtures: 4 named in the data-sources document.
  [x] CHANGELOG has [1.0.0]
  [x] CI green on main — latest conclusion: success
  [x] composer audit clean — No security vulnerability advisories found.
  PASS: 9 checks, 0 manual
  ```
  Lower than the 62 reported in gate A above despite `main` gaining commits since: as noted there, `gitleaks git` with no `--log-opts` scans every local ref in the checkout it runs in, not just `main`'s ancestry, so this count tracks how many stray local branches happened to exist in whichever working copy ran the scan — it has nothing to do with how many commits `main` itself has gained between the two runs. Two runs in two different working copies are not two measurements of the same growing total, so neither a rise nor a fall between them means anything on its own; only "clean" (no leaks in whatever was scanned) is the load-bearing result.

  This is gate A mechanical only. The blocklist-terms decision (open item 1, F1 above) is separate — the terms it would add are the owner's to supply — so gate A staying green here does not close that item; it remains open.
- **Gate C dry run** (fresh clone, not the owner's own confirmation): `git clone --depth=1` into a scratch directory, `.env` with non-default ports (`VD_HTTP_PORT=8090`, `VD_DB_PORT=3315`, `VD_MAILPIT_SMTP_PORT=1033`, `VD_MAILPIT_UI_PORT=8033`), then the README quick start. Result: `docker compose exec -T app sh -c "composer install && php artisan key:generate && php artisan migrate --seed --force"` completed clean (10 migrations, `CoreSeeder`/`TaxonomySeeder`/`SourceSeeder`/`ExampleDataSeeder` all `DONE`); the Node docs-bundle step copied `standalone.js` into `public/vendor/scalar/`; `GET /v1/health` → `200`; `GET /docs` → `200`; keyed `GET /v1/makes/dacia` → `200` with `id` as the first key (`01M2RRSQAKP46TXG8X70SFE0N0`); `GET /v1/makes/01M2RRSQAKP46TXG8X70SFE0N0` → `200`. Torn down with `docker compose down -v` and the scratch directory deleted. This dry run does not close gate C — only the owner's own run and the yes/no confirmation above does.

  **Finding — the quick start collides with an already-running stack.** `compose.yaml` pins a fixed top-level `name: vehicle-data-api`, so Docker Compose addresses containers, networks and volumes by that fixed project name regardless of which directory `docker compose` runs from. Following the README's `docker compose up -d --build` literally from the fresh clone, while the primary dev stack (same repository, a different working copy) was already running, did not create a second, isolated stack: it recreated the *existing* `vehicle-data-api-{app,db,mailpit}-1` containers in place, briefly taking the running dev stack down (port 8087 stopped answering) and rebuilding them from the fresh clone's code and `.env` instead. The named database volume was not lost (`down -v` was never run against it), so the dev stack came back once rebuilt from its own directory, but the swap itself was silent — nothing in the command output signals that a second checkout is reusing the first one's containers. Worked around here by passing `-p vd-fresh` (a distinct Compose project name) on every `docker compose` call for the fresh clone, which the README did not mention at the time of this dry run. A reader keeping their primary checkout's stack up while trying the quick start from a second clone (exactly this dry run's scenario) would have stopped their own running stack without any error telling them why. **Fixed in this same commit** (`compose.yaml`'s `COMPOSE_PROJECT_NAME` override plus the README note this gate log entry itself was added by) — see README "Try it in six commands".
- **Capacity after the hardening** (`docs/quality.md`, the first five Phase 1 hardening changes): 100 users, no think time — 65.5 rps, p50 1.43 s, p95 2.29 s, 0 errors, against a re-measured baseline of 46.2 rps on the identical procedure. Measured on a two-CPU laptop container; this is a laptop-container figure, not a production promise.

### 2026-09-26 — main repaired (development repository, `1ce1530`)

- **Development repository `main` at** `1ce1530` (a squash-merged repair of the incident that had left `main`'s `ci` red: route snapshot, docs spec, changelog fold, Dependabot groups). CI green on main: yes (2026-09-26).
- **Gate A** (`php scripts/release_check.php v1.0.0`, run on host PHP from a worktree of the development repository): all 9 lines `[x]`.
  ```
  Release check for v1.0.0
  [x] blocklist: working tree clean
  [x] blocklist: full history clean
  [x] gitleaks: full history clean — 0 commits scanned (pinned zricethezav/gitleaks:v8.30.1)
  [x] no tracked file > 100 KB (lock files exempt)
  [x] no tracked image or binary file
  [x] sources: licence + attribution + fixtures documented — Sources: 4 with an admitted licence, licence URL and attribution; fixtures: 4 named in the data-sources document.
  [x] CHANGELOG has [1.0.0]
  [x] CI green on main — latest conclusion: success
  [x] composer audit clean — No security vulnerability advisories found.
  PASS: 9 checks, 0 manual
  ```
  This worktree's copy of `scripts/release_check.php` predates the dated-CHANGELOG-heading check added since, so `CHANGELOG has [1.0.0]` passes here on the heading's presence alone (`## [1.0.0] — unreleased`), not on a date; it is not yet gate-A evidence that the CHANGELOG has been dated for the tag.

### 2026-09-26 — v1.0.0 released (this repository)

- **Published** as this public repository from one snapshot commit, `e3e8126`, of the development repository's reviewed tree (ADR 0006, addendum 2026-09-26). First `ci` run on `e3e8126`: `success` on every job, `deploy-dry-run` included; branch protection with the seven required contexts and `enforce_admins` set afterwards.
- **Gate C** public-clone run: pass (section C).
- **Tag** `v1.0.0` on `main` at `2d5cf1c64c08cb9cef3bc32d7bc93b9a2d2d9347` (the release pull request); the `ci` run on that `main` commit: `success`.
- **Gate A on the tag** (`ci` run 36257187469, `release-check` job, `--direct`): `success`.
  ```
  [x] blocklist: working tree clean
  [x] blocklist: full history clean
  [x] gitleaks: full history clean — 6 commits scanned
  [x] no tracked file > 100 KB (lock files exempt)
  [x] no tracked image or binary file
  [x] sources: licence + attribution + fixtures documented — Sources: 4 with an admitted licence, licence URL and attribution; fixtures: 4 named in the data-sources document.
  [x] CHANGELOG has [1.0.0]
  [x] CI green on main — latest conclusion: success
  [x] composer audit clean — No security vulnerability advisories found.
  PASS: 9 checks, 0 manual
  ```
  The gitleaks count covers every ref fetched in the job, including Dependabot branches, not only `main`'s two commits.
- **Release:** https://github.com/tudorandrian/vehicle-data-api/releases/tag/v1.0.0

### 2026-09-26 — v1.0.1 released (patch)

- **Why:** an independent review of v1.0.0 found that the seeded data could be read as real statistics, and it found one filter defect. Its findings are S1–S9 in [known limitations](known-limitations.md); what v2 changes is in the [roadmap](roadmap.md).
- **Changes:**
  - #4 updates `undici` and `@scalar/api-reference`. It closed every open Dependabot alert, and `npm audit` went from 13 affected packages to 6 low.
  - #7 applies a zero range bound on the variant list.
  - #8 adds the sample-data notices in the README, the OpenAPI description and `SECURITY.md`, plus S1–S9 and the roadmap.
  - #9 is the release pull request.
- **Tag** `v1.0.1` on `main` at `7810025` (the release pull request). The `ci` run on that `main` commit, 36265555224, succeeded.
- **Gate A on the tag** (`ci` run 36266152612, `release-check` job, `--direct`): `success`, `PASS: 9 checks, 0 manual`. gitleaks scanned 9 commits.
- **Gate C:** not re-run for a patch whose code change is one filter condition with its regression test. The README quick start is unchanged since the v1.0.0 run.
- **Release:** https://github.com/tudorandrian/vehicle-data-api/releases/tag/v1.0.1

## Release procedure (owner, after gates A–C)

v1.0.0 is published as a new public repository, `tudorandrian/vehicle-data-api`, that starts from one orphan commit of the development repository's reviewed tree (ADR 0006, addendum 2026-09-26). The development repository is renamed `vehicle-data-api-dev` and stays private. One sequence, run in this order; each step starts only when the previous one has finished. The snapshot is built and fully checked (step 1) before anything is renamed or published.

### 0. Preconditions, in the development repository

- `main` holds the reviewed tree, and the working copy is a clean checkout of it.
- Gates A–C above pass there (all but gate C's public-clone step, which follows step 4), run locally: the checks under "Running the checks locally" in `docs/quality.md` and `php scripts/release_check.php v1.0.0`.
- That workstation gate A is expected to print exactly one failing line, `[ ] CI green on main`, because Actions are unavailable in the development repository (if it prints `[x]` there, the run it reports is an older commit's and is not evidence either), plus the `[?] … (manual: date not set)` CHANGELOG line until step 5 adds the date. Nothing else may fail. The local runs stand in for CI there; the binding CI gate is the public repository's first `ci` run (step 4).
- The owner has said an explicit yes to the publication, the tag and the GitHub release.

### 1. Build and check the snapshot (fail-closed, no remote involved)

Run from the development checkout. The snapshot commit has the reviewed tree, no parent, and the GitHub noreply address as author and committer. It is built in a new directory next to the development checkout, which later becomes the working copy of the public repository. The block runs as one `bash -eu -o pipefail` script: the first command or check that fails stops it, and only a run that reaches the end records the checked commit in `.git/snapshot-checked`, which step 3 requires.

```bash
bash -eu -o pipefail <<'SH'
git switch main
git pull --ff-only
test -z "$(git status --porcelain)"        # a clean checkout of the reviewed main
test ! -e ../vehicle-data-api-public       # the target directory must not exist yet
tree=$(git rev-parse 'HEAD^{tree}')
noreply="87392665+tudorandrian@users.noreply.github.com"
snap=$(GIT_AUTHOR_NAME="Tudor Andrian" GIT_AUTHOR_EMAIL="$noreply" \
       GIT_COMMITTER_NAME="Tudor Andrian" GIT_COMMITTER_EMAIL="$noreply" \
       git commit-tree "$tree" -m "chore(release): v1.0.0 snapshot" \
         -m "Snapshot of the reviewed development tree (ADR 0006, addendum 2026-09-26)." \
         -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>")
git branch -f snapshot-v1.0.0 "$snap"
git clone --no-local --single-branch --branch snapshot-v1.0.0 --no-tags . ../vehicle-data-api-public
git branch -D snapshot-v1.0.0

cd ../vehicle-data-api-public
git branch -m snapshot-v1.0.0 main
git remote remove origin
git config core.hooksPath .githooks

# One commit, the noreply identity, the reviewed tree, no remote, nothing uncommitted
test "$(git rev-list --count --all)" = 1
test "$(git log -1 --format='%an <%ae> / %cn <%ce>')" = "Tudor Andrian <$noreply> / Tudor Andrian <$noreply>"
test "$(git rev-parse 'HEAD^{tree}')" = "$tree"
test -z "$(git remote)"
test -z "$(git status --porcelain)"

# Blocklist and gitleaks over the tree and the history that will be published
php scripts/blocklist.php scan .
php scripts/blocklist.php scan-history
vol=$PWD
if w=$(pwd -W 2>/dev/null); then vol=$w; export MSYS_NO_PATHCONV=1; fi   # Git Bash on Windows
docker run --rm -v "$vol:/repo" zricethezav/gitleaks:v8.30.1 git /repo --redact --no-banner --config /repo/.gitleaks.toml

git rev-parse HEAD > .git/snapshot-checked
echo "SNAPSHOT CHECKS PASSED: $(git rev-parse HEAD)"
SH
```

It must end with `SNAPSHOT CHECKS PASSED`. If it stops earlier, fix the cause in the development repository, delete `../vehicle-data-api-public` and run it again. On Windows, the clone needs long paths (`git config --global core.longpaths true`, as in the README quick start).

### 2. Rename the development repository and repoint every clone

Once step 3 creates the public repository, the name `tudorandrian/vehicle-data-api` belongs to it, and GitHub stops redirecting that name to the renamed development repository. From then on, a push from any clone that still points at the old name lands in the **public** repository and publishes that clone's branches with their full private history, and until step 4 no branch protection stops it. So, before the rename, find every clone of the development repository, on this machine and elsewhere, and after the rename repoint each one (or delete it).

List the clones on this machine: every checkout, worktree or copy with a remote that names the old repository. Widen the search root to every place checkouts live, including automation and agent work directories:

```bash
root=~
find "$root" -name .git -prune 2>/dev/null | while read -r g; do
  d=${g%/.git}
  git -C "$d" remote -v 2>/dev/null | grep -qE 'tudorandrian/vehicle-data-api(\.git)?[[:space:]]' && echo "$d"
done
```

Then do the same by hand for every other place a clone can exist: other machines, CI runners or scheduled jobs that keep a checkout, containers and editor remote environments, and AI-agent or automation sandboxes and worktrees. For each one, run `git remote -v` there, or confirm that it no longer exists.

Rename, then repoint each clone found (every remote whose URL names the old repository, not only `origin`):

```bash
gh repo rename vehicle-data-api-dev --repo tudorandrian/vehicle-data-api --yes

git -C <clone> remote set-url origin https://github.com/tudorandrian/vehicle-data-api-dev.git
git -C <clone> remote -v   # every line must name vehicle-data-api-dev.git
```

Run the search loop above again: it must print nothing.

### 3. Create the public repository and push the snapshot

Only when every check above passed: step 1 ended with `SNAPSHOT CHECKS PASSED`, and after step 2 no clone points at the old name. The block refuses to publish unless the checked commit is still the one checked out, with no remote and nothing uncommitted:

```bash
cd ../vehicle-data-api-public
test "$(cat .git/snapshot-checked 2>/dev/null)" = "$(git rev-parse HEAD)" \
  && test -z "$(git remote)" && test -z "$(git status --porcelain)" \
  && gh repo create tudorandrian/vehicle-data-api --public --source=. --remote=origin \
  && git push -u origin main
```

Every later step runs in `../vehicle-data-api-public`.

### 4. Wait for the first `ci` run, then configure the repository

```bash
sha=$(git rev-parse HEAD)
until id=$(gh run list --workflow ci --branch main --commit "$sha" --limit 1 --json databaseId --jq '.[0].databaseId') && [ -n "$id" ]; do sleep 5; done
gh run watch "$id" --exit-status
```

When it is green:

```bash
gh api -X PUT repos/tudorandrian/vehicle-data-api/branches/main/protection --input .github/branch-protection.json
gh api repos/tudorandrian/vehicle-data-api/branches/main/protection --jq '{contexts: .required_status_checks.contexts, enforce_admins: .enforce_admins.enabled}'

gh repo edit tudorandrian/vehicle-data-api --enable-issues --enable-wiki=false \
  --enable-projects=false --enable-discussions=false \
  --enable-squash-merge --enable-merge-commit=false --enable-rebase-merge=false --delete-branch-on-merge \
  --description "Reference implementation with sample data: read-only vehicle catalogue API on openly licensed public data — Laravel 13, OpenAPI-first, ro/en, with provenance" \
  --add-topic laravel --add-topic openapi --add-topic open-data --add-topic vehicles --add-topic romania --add-topic api
gh api -X PUT repos/tudorandrian/vehicle-data-api/private-vulnerability-reporting
gh api -X PUT repos/tudorandrian/vehicle-data-api/vulnerability-alerts
gh api -X PUT repos/tudorandrian/vehicle-data-api/automated-security-fixes
gh api -X PUT repos/tudorandrian/vehicle-data-api/actions/permissions -F enabled=true -f allowed_actions=all -F sha_pinning_required=true
gh api -X PUT repos/tudorandrian/vehicle-data-api/actions/permissions/fork-pr-contributor-approval -f approval_policy=all_external_contributors
```

The branch protection is kept as code in `.github/branch-protection.json`; the check must list exactly its seven contexts, with `enforce_admins` `true`. The last two calls require every action to be pinned to a full commit SHA and hold every outside contributor's workflow run for approval (both applied on 2026-09-26, after v1.0.1). Dependabot version updates follow `.github/dependabot.yml` on their own; the `vulnerability-alerts` and `automated-security-fixes` calls turn on Dependabot alerts and security updates. Then run gate C's public-clone step (section C above).

### 5. Release pull request, in the public repository

From a branch off `main`:

```bash
git switch main && git pull --ff-only && git switch -c release/v1.0.0
```

It contains:

- the date on the CHANGELOG heading: `## [1.0.0] — YYYY-MM-DD` (the `release-check` job on the tag fails on an undated heading);
- the numbers in `docs/quality.md` (and the same figures quoted in `docs/engineering-notes.md` and in the reader table above) refreshed from this repository's `ci` run of the last `main` commit, each labelled "measured in CI at `<sha>` (<date>)" in place of the development-repository label;
- the review sentence at the end of `docs/engineering-notes.md` "How it was reviewed", stating the outcome of the final review;
- the status line at the top of this page;
- the gate A wording in section A: `php scripts/release_check.php` passes with `[x]` on every line except, on a workstation before the date is set, the `[?]` manual CHANGELOG line.

```bash
git commit -a -m "docs: release v1.0.0"
git push -u origin release/v1.0.0
gh pr create --title "docs: release v1.0.0" --body "CHANGELOG date, CI figures from this repository, review outcome, status line, gate A wording."
gh pr checks --watch
```

### 6. Merge, tag and publish the release

1. When the required checks on the release pull request are green, squash-merge it and update the local `main`:
   ```bash
   gh pr merge --squash
   git switch main && git pull --ff-only
   ```
2. Wait until the `ci` run on that `main` commit has succeeded:
   ```bash
   sha=$(git rev-parse HEAD)
   until id=$(gh run list --workflow ci --branch main --commit "$sha" --limit 1 --json databaseId --jq '.[0].databaseId') && [ -n "$id" ]; do sleep 5; done
   gh run watch "$id" --exit-status
   ```
3. Tag that commit and push the tag:
   ```bash
   git tag -a v1.0.0 -m "vehicle-data-api 1.0.0 — public core" "$sha"
   git push origin v1.0.0
   ```
4. Watch the tag's `ci` run, including `release-check`, then create the release:
   ```bash
   until id=$(gh run list --workflow ci --branch v1.0.0 --commit "$sha" --limit 1 --json databaseId --jq '.[0].databaseId') && [ -n "$id" ]; do sleep 5; done
   gh run watch "$id" --exit-status

   # Release notes: the [1.0.0] section of the CHANGELOG, up to the next "## [" heading or the end of the file
   awk '/^## \[1\.0\.0\]/ { on = 1; next } on && /^## \[/ { exit } on' CHANGELOG.md > release-notes.md
   gh release create v1.0.0 --title "v1.0.0" --notes-file release-notes.md
   rm release-notes.md
   ```
5. Append an entry to the gate log above through a pull request: the tagged SHA, the tag's `ci` run and its `release-check` result, and the release URL.
   ```bash
   git switch -c docs/gate-log-v1.0.0
   # edit docs/release-gate.md
   git commit -a -m "docs(release-gate): gate log entry for v1.0.0"
   git push -u origin docs/gate-log-v1.0.0
   gh pr create --title "docs(release-gate): gate log entry for v1.0.0" --body "Tag, tag ci run, release-check result, release URL."
   gh pr checks --watch && gh pr merge --squash
   git switch main && git pull --ff-only
   ```

Pushing the tag also triggers `deploy.yml`, whose `deploy` job is skipped in this repository because no deploy secret is set.
