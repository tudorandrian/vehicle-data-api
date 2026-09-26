# ADR 0008 - Validate cached credentials against the primary database

Date: 2026-09-20 · Status: accepted

## Context

Resolved clients are cached for up to 60 seconds to avoid repeatedly loading scopes and constructing client metadata. Clearing the cache before and after revocation or rotation does not prevent a concurrent resolver from writing its previously read active client after both clears. That stale entry would authorize later requests despite the command having completed.

## Decision

Before using cached client metadata, query the primary database for the presented key hash, requiring no disable timestamp and an expiry strictly in the future (or no expiry). Evict an invalid entry and reject the credential. Continue to cache metadata and clear entries on rotation/revocation; invalidation is an optimization, not the authority for credential validity.

The guarantee applies to authentication checks after the credential change commits. A request authenticated before that point may complete; revocation does not cancel in-flight work. Metadata such as scopes, origins and limits can remain cached for its existing TTL. Credential validity must not depend on a lagging read replica.

## Alternatives

- Shorter TTL or double deletion: reduces exposure but does not remove the race.
- Distributed locking around resolution and mutation: adds shared lock lifetime, timeout and failure handling to every caller and command; too much machinery for this deployment.
- Remove the cache: correct, but also reloads scope relations and metadata on every request. Retaining metadata caching with an indexed validity check is the smaller change.

## Consequences

Warm authenticated requests add one indexed database query. The query budget is updated explicitly and the performance tests still apply; older capacity measurements are not measurements of this change. Database failure cannot cause cached credentials to be accepted. Deterministic regression tests restore a stale cache entry after both rotation and revocation and require a 401. No key format, extension-interface signature or response schema changes.
