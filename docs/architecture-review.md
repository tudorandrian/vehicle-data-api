# Architecture and implementation review

Date: 2026-09-20. Baseline: the development repository at `55a5306`. Scope: the public application and core package, before the first release.

The modular monolith is appropriate for the documented shared-hosting target. Keep it. The main risks are stronger guarantees in documentation than the implementation actually provides, ambiguous data identity, and operational assumptions that small fixture tests cannot establish. Adding services, Redis or a generic repository layer would not resolve these problems.

Decisions per finding: [known limitations](known-limitations.md).

This is a code and contract review, with targeted regression tests and the repository checks recorded below. It is not a penetration test, a full-history secret audit, a production capacity measurement or a legal review of source licences. No live data was fetched or changed. The deployment-scale and existing-consumer questions remain open; until answered, the recommendations assume modest traffic on shared hosting and preserve `/v1` compatibility.

## Approaches used

| Direction | Method | What it reveals |
|---|---|---|
| Architecture | Trace routes through middleware, controllers, resources and extension contracts | Whether documented boundaries are enforced and whether extensions can actually supply new kinds |
| Security and privacy | Follow credentials and request data through cache, logs, monitoring and deployment | Revocation races, URL disclosure and development services exposed beyond the machine |
| Correctness | Compare OpenAPI, documentation and code; construct failure-path regressions | Nullable-field removal, false readiness and partial client creation |
| Concurrency and recovery | Walk through interleavings, transaction boundaries and process termination | Import locking, cache repopulation, snapshot consistency and incomplete run bookkeeping |
| Data modelling | Compare natural keys, provenance and update rules | Silent grouping, last-writer selection and incomplete incremental sync |
| Operations and testing | Inspect CI gates, deployment scripts, lifecycle choices and test datasets | Unsupported database baseline, ungated deployment and limits of performance evidence |

## Decisions worth retaining

| Decision | Why it is a good fit | Boundary to keep explicit |
|---|---|---|
| Laravel application plus a domain package (ADR 0002) | One deployable process, relational transactions and familiar tooling match the hosting constraint; the package keeps private changes out of the public core | The host application still owns middleware and the scheduler; copying bootstrap files is an upgrade obligation |
| OpenAPI plus executable contract tests | Consumers can distinguish absent, null and populated fields; tests catch schema drift before release | A schema validator cannot prove identity, freshness, privacy or race safety |
| Stable public identifiers and retained slug aliases | Names can change without losing a stored record identifier; aliases preserve old URLs | Stable identity needs source-level identity rules, not just a stable column |
| Licence metadata and importer provenance | Imports and fixture generation are auditable, and attribution reaches consumers | Record-level provenance does not identify the winning source of each field |
| Random bearer keys stored as hashes, narrow scopes, private caching | Strong random credentials do not need password-style slow hashing; private responses avoid shared-cache disclosure | CORS is browser policy, not authentication; a public demo key is public |
| SQL upsert counters | Atomic increments work without Redis and avoid read/modify/write lost updates | Authentication failures still use database resources; edge limits remain necessary |
| Transactional importer rollback and row savepoints | A rejected row cannot leave half a rename behind; an aborted import does not partially replace the catalogue | Network fetch and full-dataset transaction duration need a separate design |
| Pinned CI actions, production debug guard, rollback exercises | These make builds and deployment failures more predictable | Passing an isolated job is not the same as requiring that job before deployment |
| Taxonomy public IDs and aliases (ADR 0009) | Stable public ids (26-character Crockford base32) survive taxonomy and term rename; retired keys remain reserved and taxonomy URLs redirect | Operators must use the rename transaction and maintain labels for the new readable key |

## Corrections in this change

These are bounded corrections to existing intent, not a redesign of the API.

| Finding | Failure mechanism and consequence | Correction and tradeoff |
|---|---|---|
| High: revocation can lose to a late cache write | `DatabaseClientResolver::resolve()` reads an active client, the command revokes/rotates and clears cache, then the older resolver writes its stale result. Later requests accept it for up to 60 seconds | Check active credential state on the primary database before reusing cached metadata (ADR 0008). One extra indexed query is preferable to overstating revocation guarantees. Requests already authenticated can finish |
| Medium: an enricher can delete a protected null | `Enrichers::apply()` used `?? null` for both sides, so absent and explicitly null compared equal | Check key presence as well as value. Contract tests now exercise both null and populated values |
| Medium: cache failure reports healthy readiness | `HealthController::ready()` set `checks.cache=fail` without changing HTTP 200 or `status=ok` | Report the already-declared HTTP 503/degraded response for failed cache probes. Authentication can itself fail before this controller; this does not promise a readiness body for every infrastructure outage |
| Medium: partial client creation | Client and scope inserts were separate; duplicate scopes hit a unique constraint after the client was saved and before the key was printed | Deduplicate scopes and create the client and scopes in one transaction; print only after successful commit |
| Medium: development services bound all interfaces | Compose published database, SMTP, mail UI and API ports without a host address; development credentials are deliberately predictable | Bind published ports to loopback. Intentional remote development access needs a reviewed local override |

## Open findings, in priority order

### R1 — High: telemetry privacy is not established

Evidence: `config/sentry.php` claimed `send_default_pii=false` prevents request headers and user data from being sent. The installed SDK's `vendor/sentry/sentry/src/Integration/RequestIntegration.php::processEvent()` still captures the full URL and query string, and retains headers outside its sensitive-header list. A VIN in `/v1/vin/{vin}` is therefore in an event's URL when reporting is enabled. The setting also does not constitute a scrubber for exception messages, SQL errors, breadcrumbs or custom context. `RecordUsage::terminate()` reports database exceptions, which can include bindings such as IP addresses in an exception message. The regular `api.request` line is much narrower than general exception reporting.

**Decision recommended:** leave the DSN unset until outbound events and transactions pass privacy tests. Add a config-cache-compatible named callback or integration with a small allow-list (route name, request id, status, safe stack metadata). Cover event request data, exception text, breadcrumbs, contexts and tracing separately. Test serialized transport envelopes with synthetic secret/VIN/IP markers; merely checking configuration booleans is insufficient. Review local exception logs too. This review corrects the misleading documentation but does not claim a telemetry scrubber has been implemented.

### R2 — High before launch: unsupported database baseline

Evidence: `compose.yaml`, required CI jobs and ADR 0001 use MariaDB 10.5; 10.11 runs only as an allowed failure. Community maintenance for 10.5 ended in 2025. Hosting parity is useful for reproducing behavior, but is not a security maintenance plan.

**Decision recommended:** obtain a supported database from the hosting provider and make that exact version a required CI target. Keep an old compatibility job only if needed during migration. Do not replace the existing image over its persistent volume blindly: rehearse backup, restore and upgrade on a copy, including collation and index plans. If the provider claims extended fixes, record its actual support commitment privately before accepting an exception. Hosting availability has not been verified in this review.

### R3 — High for data correctness: variant identity and field selection are implicit

Evidence: `EeaSource::map()` derives `natural_key` from normalized make/model, fuel, category, engine, power and Euro norm. `VariantWriter::write()` matches that same coarse tuple, while mass, CO2, type approval, variant and version codes overwrite the chosen record. Different upstream configurations can collapse into one public id; reversing row order can select different CO2/specification values. Missing values can overwrite known values. Raw EEA row ids alone are also not a safe replacement: these are source observations, not necessarily canonical model variants.

**Decision recommended:** explicitly define what a public variant represents. Separate source observations from the canonical record and establish field precedence, conflict handling and correction history. Preserve existing ids and provenance during migration. If grouping is intentional, document the grouping and how a representative value is selected; do not imply a unique homologated configuration. Acceptance: reorder the same source rows and import sources in opposite orders; canonical outputs must be equal or conflicts must be explicit. Add cases for missing values, corrected attributes and repeated years. An identity ADR and data migration design are needed before changing this behavior.

### R4 — High at full import size: network work occurs inside the write transaction

Evidence: `ImportPipeline::run()` wraps iteration of `DataSource::fetch()` in one transaction. EEA fetch performs paginated HTTP calls and disk writes during that iteration. Once earlier batches have written, a slow later page extends the lock and transaction lifetime. `BATCH=1000` uses nested transactions/savepoints; it does not commit every 1000 records. Run creation and source metadata updates happen before the catalogue transaction; the success marker happens afterward. A killed process can leave `running` indefinitely, including after catalogue commit.

**Decision recommended:** download to a run-specific, checksummed staging artifact first, validate it, then publish from local input. Retain atomic visibility; simply committing each batch would weaken the current guarantee. Measure a full import before deciding whether staging tables and a catalogue revision switch are necessary. Add a pipeline-level single-writer policy covering CLI and scheduler entry points; scheduler `withoutOverlapping()` does not coordinate every manual import. Persist committed run state with publication, and reconcile abandoned runs. Acceptance: interrupted download, competing imports, process death around commit, restart and repeated import.

### R5 — High for synchronization: `updated_since` does not cover all representation changes

Evidence: list and snapshot filters check only each row's `updated_at`. Resources embed current related slugs. Renaming a make can change every model/variant representation without advancing their timestamps; manufacturer parent reconciliation uses raw SQL without advancing child timestamps. There is no tombstone stream or catalogue revision token. Consumers can therefore miss changes even when they correctly retain the latest timestamp.

**Decision recommended:** define whether sync covers stored rows or public representations. Prefer a monotonic publication revision plus a change ledger/tombstones for reliable mirroring; a smaller first step is transactional propagation of timestamps to affected dependants. Include a bounded upper watermark and specify inclusive boundary/deduplication rules. Until then, document periodic full reconciliation and avoid promising lossless incremental sync. Acceptance: parent rename, record removal, same-second updates and a multi-page export concurrent with an import.

### R6 — Medium: cached manufacturer parent can remain incorrect

Evidence: `ManufacturerWriter::finish()` performs an inner join only where `parent_qid` resolves and is not self. A removed, unknown or self parent never clears an old `parent_slug`; parent renames update the slug without touching the child's timestamp. The resource publishes that cached slug directly.

**Decision recommended:** retain immutable internal parent identity and derive the public slug, or reconcile with a left join that sets unresolved parents to null and timestamps actual changes. Define cycle handling. Test removed parent, unresolved parent, self-parent, rename and repeat-run idempotence. Coordinate with R5 rather than adding another isolated timestamp workaround.

### R7 — Medium: ETags claim stronger semantics than they implement

Evidence: `Etag::fingerprint()` excludes `meta.generated_at` but emits a strong validator, so different representation bytes can have the same strong ETag. Conditional matching also ignores `If-None-Match: *`, weak comparison, and HEAD. Hashing and serializing occur after the database queries, so a 304 mainly saves transfer bytes, not query work.

**Decision recommended:** use a weak validator for semantic equality, or make the representation timestamp stable and retain byte-exact strong validators. Use the framework's conditional-response helpers with tests for wildcard, weak tags, tag lists and HEAD. The current documentation explicitly promises strong tags, so decide the compatibility treatment and update OpenAPI, ADR and consumers together; this review does not silently change that promise. Prefer revision-based early validation only after R5 has a dependable revision model.

### R8 — High before automated production deployment: CI and deploy are separate gates

Evidence: `.github/workflows/deploy.yml` starts on tags/manual invocation and depends only on its secret-presence check. It does not require the exact commit's tests, contract, release check or build to pass; production environment approval may exist remotely, but that was not inspected. It rebuilds rather than promoting the artifact that passed CI. `scripts/deploy.sh` smoke calls prove a healthy response, not that the response came from the intended revision. The smoke bearer header is supplied to curl as an argument, visible to local process inspection where permitted.

**Decision recommended:** promote the tested artifact identified by immutable commit and checksum, and require successful checks for that revision before deployment. Make smoke verification check the expected release id. Pass curl credentials through stdin or a restricted temporary configuration file. Add a deployment lock covering manual invocations as well as CI. Document backup/restore separately: reverting a symlink does not revert data or migrations. Keep additive migrations, and rehearse both an upgrade and a failed deployment on the real web-server family.

### R9 — Medium: snapshots are streamed queries, not consistent published artifacts

Evidence: `SnapshotController` uses `lazyById()` in separate queries without a fixed catalogue revision. An import can commit between chunks. Compression or encoding can fail after HTTP 200 has been sent. Large concurrent downloads each occupy a PHP worker and consume database resources; one request counts the same as a small catalogue read.

**Decision recommended:** for substantial catalogues, generate one immutable compressed artifact per published revision, with row count and checksum, then serve authorized downloads from that artifact. On small catalogues, retain streaming but document its consistency limits, use JSON encoding that throws on invalid data, and bound concurrent exports. Test failure after the first chunk, disconnect, retry and a concurrent import. Do not wrap a slow client download in a long database transaction just to obtain consistency.

### R10 — Medium before private extensions: extension contracts are narrower than the marketing

Evidence: kind registration supplies schemas, but `VariantWriter` and `FleetWriter` hard-code `car`; writer registration is fixed. `Enrichers` protects only class-1/2 values; class-3 collisions, null additions and schema ownership are not controlled. `KindRegistry::register()` silently replaces a duplicate kind. OpenAPI uses `anyOf` for kind fragments, which validates membership in some schema, not correspondence to a record's actual kind. The platform copies application bootstrapping and deployment files that can drift from the pinned package.

**Decision recommended:** document supported extension scenarios precisely, reserve extension key namespaces and reject duplicate registrations. Add an extension compatibility fixture exercising source import, kinds, output and assembled contracts end to end. Introduce writer registration only when a concrete second kind requires it. Put shared middleware setup in a supported package integration entry point before multiple hosts duplicate it. Keep the four public interfaces small rather than making every internal class an extension point.

### R11 — Medium: operational limits need tests beyond fixture throughput

Evidence: failed authentication is counted after resolution and still logs/records attempts; `LimitBodySize` checks the declared Content-Length only and runs in route middleware. `RecordUsage` sees no buffered body for streamed responses, so its byte count is not download size. `AggregateDailyUsage` loads and sorts every duration for a client/day in PHP. Raw downloaded artifacts and import-run rejection samples have no bounded cleanup in the retention table. The WMI rejection allowance of 1.0 can classify a fully rejected feed as a successful run.

**Decision recommended:** enforce request body, request rate and connection limits at the edge; distinguish transfer metrics from buffered response bytes; calculate daily percentiles with bounded memory; define artifact/reject retention and minimum accepted-row/freshness alerts. Test malformed/oversized traffic through the actual server, not only Laravel's test kernel. Expand load tests to production-sized data, imports plus reads, 429 paths, snapshots and cold caches. Existing benchmark numbers remain historical measurements on small fixtures, not a production capacity promise.

### R12 — Medium: policy and release checks should not overstate enforcement

Evidence: the import pipeline accepts the licence provided by a source and writes it before the separate `vehicle:sources check` gate. The platform bootstrap previously recommended hand-curated vehicle facts despite the core's importer/source policy. The oasdiff job skips its check for the `breaking` label; it does not itself prove that a `/v2` route and a new major version exist. Only the base OpenAPI document is compared for breaking changes, while kind fragments are assembled later. SECURITY excluded every finding involving leaked keys, which could exclude a real scope-isolation defect.

**Decision recommended:** distinguish trusted extension code from untrusted upstream data; validate admitted source/licence policy before publication, not just after import. Compare assembled contracts between revisions. Treat the breaking label as review metadata, with a separate major-version/path check. Keep restricted-key isolation and accidental disclosure in vulnerability-reporting scope. The bootstrap and security wording are corrected in this change; the remaining automated gates are follow-up work.

### R13 — Resolved: public taxonomy IDs are necessary because names and codes can change

`vd_taxonomies.name` is unique and `vd_taxonomy_terms` enforces unique `(taxonomy_id, code)`, but neither constraint preserves the old meaning once a key changes. An internal numeric database key is unsuitable outside one database. ADR 0009 adds portable public ids (26-character Crockford base32) to taxonomy and term responses. Consumers can therefore store a stable identity even if the current `name` or `code` changes.

The migration also reserves retired taxonomy names globally and retired term codes within their taxonomy. `TaxonomyIdentity` performs rename, alias retention, conflict protection, parent-code changes and the built-in variant-reference change in one transaction. An old taxonomy URL returns a query-preserving 301 to its current URL. The remaining publication responsibility is operational: update labels/translations in the same change, and use the service instead of direct SQL.

The original gap was lifecycle, not identifier shape: a term-code rename would silently invalidate persisted filters and branching logic. Public IDs are immutable in `/v1`; names and codes are mutable readable keys with retained aliases. The variant filters accept retired term codes and resolve them to the current code while consumers migrate. A future external vocabulary whose codes are mutable needs its own mapping and lifecycle decision before exposure.

## Implementation order and decisions still needed

1. Before deployment: validate telemetry privacy or leave it disabled (R1), settle supported database hosting (R2), and enforce the deployment gate (R8).
2. Before treating the catalogue as durable consumer data: decide variant identity/precedence (R3), repair parent reconciliation (R6), then design publication and sync together (R4, R5, R9).
3. Before freezing the extension API: exercise a real second kind/source and decide extension key ownership (R10).
4. Before raising capacity: run realistic workload tests and set retention/edge limits (R11); correct validator semantics with the compatibility decision (R7), and finish policy gates (R12).

Owner inputs: expected catalogue size and request/export load; whether any consumers already persist `/v1` ids; available supported database versions; whether snapshots require a consistent point in time; whether variants represent homologated configurations or grouped source observations; and whether external telemetry is needed. These answers affect migrations and service guarantees, not the bounded fixes above.

## Validation

All PHP commands ran inside the Compose `app` container against the isolated test database.

| Check | Result |
|---|---|
| Baseline Pest suite, excluding `network` and `browser` with separate flags | 420 passed, 2,538 assertions |
| Focused credential, client-command, field-class, readiness and query-budget regressions | 37 passed, 145 assertions |
| Final full Pest suite | 427 passed, 2,573 assertions |
| `vendor/bin/pint --test` | Passed, 259 files; one formatting issue was corrected before the final run |
| `vendor/bin/phpstan analyse --memory-limit=1G` | Passed, no errors |
| `npx spectral lint packages/vehicle-data-core/resources/openapi/openapi.yaml` | Passed, no errors |
| `composer audit --no-interaction` and `npm audit --ignore-scripts` | No reported advisories at review time |
| `php scripts/blocklist.php scan .` and `git diff --check` | Passed |
| Compose configuration | All four published ports resolve to loopback |

Live-source, browser, production load, real-host recovery and complete-history release-gate checks were not run for this change. Passing the listed checks does not close the open architectural findings above.

## External references

- [HTTP validator semantics, RFC 9110 section 8.8](https://www.rfc-editor.org/rfc/rfc9110.html#section-8.8): strong validators identify byte-level representation changes; semantic equivalence uses weak validators.
- [MariaDB maintenance policy](https://mariadb.org/about/#maintenance-policy): community maintenance periods; verify provider-specific support independently.
- [Sentry PHP request integration](https://github.com/getsentry/sentry-php/blob/master/src/Integration/RequestIntegration.php): request URL collection is separate from the default-PII switch. This finding was checked against the installed dependency, not inferred solely from the upstream main branch.
