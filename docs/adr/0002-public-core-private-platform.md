# ADR 0002 - Public core, private platform

Date: 2026-09-16 · Status: accepted

## Context
The open-data backbone should be public (portfolio, reuse, scrutiny), while enriched facets, further vehicle kinds, client-specific policy and the live deployment stay private. A fork would drift; a bare library would not prove that the API runs.

## Decision
- This repository is a **complete, deployable Laravel application** whose domain lives in a **path package**, `packages/vehicle-data-core` (`tudorandrian/vehicle-data-core`, namespace `VehicleData\Core`).
- The private platform is a separate repository that adds this one as a **Git submodule pinned at a release tag**, requires the package by path from the submodule, and calls this repository's reusable `deploy.yml` at the same tag.
- Extension happens only through **four extension contracts** in `src/Contracts/`: `DataSource` (new licensed sources), `Enricher` (class-3 keys only), `SpecificationSchema` (vehicle kinds and their OpenAPI fragments), `ClientResolver` (key storage). `RecordWriter` and `RunAware`, also in that directory, are internal to the import pipeline, not extension points.

Rejected: **package only** (nothing runnable to review; CI could not prove the HTTP contract end to end); **fork** (every core fix would need a merge, and private changes could leak into the public history).

## Consequences
The core must stay free of private names and data (ADR 0006) and keep the contracts stable: changing a contract signature is a breaking change for the platform and needs a major version. What the platform needs but the contracts cannot express is added to the core first, in public. Bootstrap steps: docs/platform-bootstrap.md.
