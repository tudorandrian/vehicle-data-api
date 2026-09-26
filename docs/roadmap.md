# Roadmap to v2

v1 is a reference implementation: a working, tested API that you run yourself, loaded with a small committed sample of each source (see [What the sample covers](data-sources.md#what-the-sample-covers)). v2 turns it into a data product that can be relied on at full size. This page lists what v2 must change. Each item names the finding it answers ([known limitations](known-limitations.md): R1–R12 from the architecture review, S1–S9 from the independent review of v1.0.0) and the check that proves it done.

v1.x keeps receiving security fixes and small, compatible corrections as patch releases (see [SECURITY.md](../SECURITY.md)). Breaking changes ship under `/v2`; the `/v1` routes they replace get `Deprecation` and `Sunset` headers ([Evolution](api.md#evolution)).

## 1. Data that says what it covers

| Change | Answers | Done when |
|---|---|---|
| Coverage metadata on every catalogue response: whether the data is the committed sample or a full import, the geographic and temporal coverage of each source, and the completeness of the last import | S1 | A consumer who sees only an API response can tell a sample from a national total; contract tests cover the new members |
| `ro_fleet` recomputed and labelled per import run (reference date, counties included, rows aggregated) | S1 | A full DRPCIV import reports national totals with their reference date; a partial import is labelled partial |
| Variants separated from the source observations they group: one observation per EEA row, a deterministic rule for the representative values, the observation year and provenance per attribute group | R3 | Re-importing an older year cannot change the values a variant shows; each value names the observation it comes from |
| Data-quality flags that keep the raw value (for example a 12 kW petrol Duster) and state the rule that flagged it | S2 | Implausible values are flagged, never silently corrected, and the flag rules have tests |
| A source for `body_type`, `gearbox`, `drive` and `colour`, or their removal from the public taxonomy list | README "Status" | Every published taxonomy has at least one populated term |

## 2. Safer by default

| Change | Answers | Done when |
|---|---|---|
| `npm audit` gate in CI for the Node/Scalar build graph, with documented, dated exceptions | S3 | CI fails on a new high or critical advisory; exceptions carry a reason and a review date |
| MariaDB 10.11 (or a newer supported series) as the default in Compose and `.env.example`; 10.5 kept only as a compatibility leg | R2, S4 | A fresh install runs a series under community maintenance |
| A tested telemetry sanitiser (URLs, VINs, exception messages, headers) before Sentry can be enabled, with a retention rule | R1 | Tests show that no VIN, key or query string reaches an event |
| Request body size enforced at the web server as well as in the app, including bodies without `Content-Length` | R11 | The Compose and deployment configurations reject an oversized chunked body |
| Deployment bound to the checks of the exact commit or tag being deployed | R8 | `deploy.yml` refuses a SHA whose `ci` run is not green |
| `oasdiff` over the runtime-assembled contract (with kind fragments), and a guard that a `breaking` label comes with a new major version | R12 | A breaking change to any served fragment fails CI unless it ships under `/v2` |
| Unknown names in `fields` answered with `422` instead of being dropped | S5 | A typo in `fields` is reported, not mistaken for an absent value |

## 3. Imports and sync that scale

| Change | Answers | Done when |
|---|---|---|
| Fetch and validate a source before the write transaction; stage, then publish | R4 | No network call runs inside a write transaction; a failed fetch changes nothing |
| One import at a time (lock), and reconciliation of runs left `running` by a killed process | R4 | A second concurrent run is refused; `vehicle:status` shows stale runs |
| Minimum-rows and feed-structure checks per source, including small files | R11 | A fully rejected WMI feed fails the run |
| Streaming aggregation of the fleet file instead of holding it in memory | S6 | A full-size DRPCIV import runs within a stated memory limit, measured in CI or a documented benchmark |
| Published revisions: versioned, consistent snapshots and a change ledger that also records representation changes | R5, R6, R9 | A client can sync by revision without missing a make rename; a snapshot is one consistent revision |

## 4. Easier first use

| Change | Answers | Done when |
|---|---|---|
| A bootstrap script that checks prerequisites and free ports, and ends by printing the URL and a first request | S7 | A first-time user on Windows, macOS or Linux reaches the first `200` with one command |
| A PowerShell path for every quick-start command | S7 | The README quick start works in PowerShell without Git Bash |
| A troubleshooting section for `401`, `403` and browser origins | S7 | Each common first-use error links to its fix |

## 5. Extension contracts that match the interfaces

| Change | Answers | Done when |
|---|---|---|
| Writers that take the vehicle kind from the registered schema instead of hard-coding `car`, and a registry that rejects a duplicate kind | R10 | A second kind imports end to end through a public extension point, with a test |

The independent review also recorded what v1 already does well: the separation of the domain package from the host app, the verified contract and examples, stable identities with aliases, provenance and licensing per record, and key handling. v2 keeps those properties; every change above ships with tests and a contract update.
