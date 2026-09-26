# ADR 0004 — Field classes and API evolution

Date: 2026-09-16 · Status: accepted

## Context
Open data is incomplete: a variant may lack a CO2 value, a manufacturer its founding year. Consumers (web sites, scripts, a private extension) need to tell "unknown" from "zero", and to know which changes can break them.

## Decision
- **Three field classes**, declared per resource and checked by contract tests: class 1 always present and never `null`; class 2 always present, `null` when no held source has the value; class 3 omitted when absent (`ro_fleet`, `logo`, `specifications`). Unknown is never `0` or `""`. `id` (ADR 0007) is class 1 on every catalogue resource, alongside `slug` and `name`.
- **Additive changes** (routes, optional parameters, keys, terms, kinds) stay in `/v1`; consumers must ignore unknown keys.
- **Breaking changes** (removal, rename, type change, class demotion, a new required parameter) ship as `/v2`; the replaced `/v1` routes get `Deprecation`, `Sunset` and `Link` headers (`core.deprecations`). oasdiff blocks breaking pull requests unless they carry the `breaking` label.
- **Kinds** come from a registry (`KindRegistry`): each kind brings a JSON Schema for `specifications` and an OpenAPI fragment assembled into `/openapi.yaml`.
- **Every schema change** is one pull request with an ADR (when it is a new decision), an additive migration, the OpenAPI change with a contract test, and a CHANGELOG entry.

## Consequences
Clients can rely on key presence for classes 1 and 2 and on `null` meaning "not known". Enrichers can only add class-3 keys, so the private extension cannot silently change public semantics. A new vehicle type needs no route change.
