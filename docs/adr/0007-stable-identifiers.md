# ADR 0007 - Stable identifiers and slug aliases

Date: 2026-09-17 · Status: accepted

## Context
Makes and models were keyed only by `slug`, derived from the name; a normaliser change or a source correction would create a new record and orphan the old URL. Variants had a deterministic `public_id`, but its natural key contained the make and model names. Numeric ids were rejected from the start (environment-dependent, enumerable). Integrators need one value they can store for years.

## Decision
- Every catalogue record (manufacturer, make, model, variant) exposes `id`: 26 characters of Crockford base32 (a ULID for manufacturers, makes and models, assigned once at insert; the deterministic hash for variants, kept for the life of the record). `id` is class 1 and **never changes**.
- `slug` stays the human-readable, canonical URL key and **may change**. Every retired slug is kept in `vd_slug_aliases`; a request for it answers `301` to the current URL (query string kept), cached for a day. Keyed routes accept an id or a slug in the same position.
- Identity across imports is by provenance: the raw source value (`vd_record_sources.source_ref`; for a model it is the make's `id` plus the raw model value, so renaming a make does not orphan its models), then the slug of the normalised name (same kind), then creation. A rename therefore keeps the `id` and updates the record, storing the old slug as an alias; a new slug that already belongs to another live record, or to another record's alias (any record of the same type), rejects the row (`slug_collision`) rather than silently merging or stealing it - no silent merges. A source reference longer than 120 characters rejects the row (`ref_too_long`) rather than truncating it. A rejected row is rolled back entirely (a per-row savepoint around identity resolution and the write) and listed in the run's reject report; it never leaves a partial write. Manufacturers are resolved by their immutable `wikidata_qid` rather than by provenance or slug, but a manufacturer rename goes through exactly the same machinery as a make or model: the old slug is kept in `vd_slug_aliases` and answers `301`, and a slug already live or aliased for that record type rejects the row (`duplicate_slug` at map time, `slug_collision` at write time).
- Cross-references in bodies (`manufacturer`, `make`, `model`, `parent`) stay slugs, current at response time.
- Partner identifiers (an insurer's numeric make id, an ERP code) are provenance rows written by a private `DataSource`, not fields of this API.

Rejected: numeric ids; deterministic ids for makes (a hash of the name changes with the name); `*_id` beside every reference (doubles the payload for no new guarantee); dropping slugs (URLs stop being readable).

## Consequences
Store `id`; use `slug` for URLs and display. Follow 301s. A 301 has no body and no `Content-Type`; the requested key still needs a valid key with the route's scope, like any other response (a missing, wrong-scope or otherwise unauthorised key still gets `401`/`403` first). `links.self` on a list echoes the requested URL as sent, so a list requested by id keeps that id in `links.self`; only the `Location` of a redirect and other server-built URLs use the current slug. A model's slug follows its make's name and is updated when the source next provides the model. The variant natural key remains the EEA row identity, so fixture ids are identical on every machine.

## Addendum 2026-09-26 - deterministic first minting (Phase 2)
Records that arrive with a provenance ref mint their first id as `PublicId::for('<type>|<source key>|<raw ref>')` (manufacturers: `'manufacturer|wikidata|<qid>'`; taxonomies and terms: `'taxonomy|<name>'`, `'term|<taxonomy>|<code>'`). The id is still assigned once and never recomputed; existing rows keep theirs. The objection above to hashing the natural key does not apply: a rename changes the name, never the raw ref, which is what provenance already keys on. Reason: every fresh seed of the committed fixtures produces the ids the documentation and the OpenAPI examples show, and the examples check in CI can diff a seed against the committed files.
