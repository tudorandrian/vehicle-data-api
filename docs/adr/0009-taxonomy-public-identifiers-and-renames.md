# ADR 0009 - Taxonomies have stable public IDs and reserved renamed keys

Date: 2026-09-20 · Status: accepted

## Context

Taxonomy names and term codes are readable and already appear in routes, filters and catalogue records. They are nevertheless mutable in real operations: a source standard can correct a name, a vocabulary can be reorganised, or a code can be clarified. Using either as the sole external identity would turn a rename into a broken stored reference or, worse, allow the old key to be assigned to a different concept.

## Decision

Expose a 26-character ULID `id` for every taxonomy and term. It is assigned once, independent of the database numeric key, and is the identity consumers store. `name` and `code` are current readable keys.

Keep retired taxonomy names in `vd_taxonomy_aliases`, globally unique, and return `301` to the current taxonomy URL. Keep retired term codes in `vd_taxonomy_term_aliases`, unique within a taxonomy. Aliases are not reused by a different taxonomy or term; the variant filters accept them and resolve them to the current code. `TaxonomyIdentity` is the write path for renames: it performs the conflict checks, alias write, canonical-key update, parent-code update and relevant variant-reference update in one transaction. Renaming also requires labels/translations for the new key before publication.

## Consequences

Adding `id` is additive in `/v1`; current names and codes remain in responses. A taxonomy can be found through its ID after any rename, while old named URLs redirect. Retired term codes are preserved as historical compatibility keys and are never reassigned. A future term-specific route can use the term ID without reshaping the resource identity model.

The schema has a little more storage and the operator must use the dedicated rename path rather than editing rows. This cost is justified because it avoids irreversible identity conflicts and data loss during vocabulary maintenance.
