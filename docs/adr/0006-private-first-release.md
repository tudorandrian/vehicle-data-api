# ADR 0006 - Private first, public at v1.0.0

Date: 2026-09-16 · Status: accepted

## Context
The repository is built in private and made public at its first release. Git history is permanent once public: anything committed on the way (a consumer's name, a hostname, a key) would be published with it.

## Decision
- **Every commit is publishable** from the first one: no secrets; no consumer site, company, partner or client names; no hosting hostnames, IPs or account names; no local machine paths or user names; no file over 100 KB (lock files exempt); no images.
- A **hash-based blocklist** (`scripts/blocklist.hashes.json`, SHA-256 of lower-cased tokens and their variants) enforces the names without the names ever entering the repository; the clear-text list stays outside Git. gitleaks covers secrets. Both run in the pre-commit hook and in CI.
- **Three release gates** (docs/release-gate.md): A, mechanical (`scripts/release_check.php`: blocklist over the tree and the full history, gitleaks, size and type limits, licences and fixtures, CHANGELOG, CI, `composer audit`); B, a blind review by readers who get only the public files; C, the owner's fresh-clone run and final confirmation.
- **No history rewrite**: whatever a gate finds is fixed forward and, for a secret, rotated. A rewrite would mean the gates never examined the history that gets published.

## Consequences
Making the repository public is a settings change after the gates pass, not a clean-up project. Some context (who the consumers are, where the service is hosted) lives only in the private platform. A newly discovered sensitive term is added to the blocklist and the full history is scanned again before release.

## Addendum 2026-09-26 - published by snapshot

- v1.0.0 is published as a new public repository that starts from a single commit of the development repository's reviewed tree, not by making the development repository public.
- The development history, including its review rounds, stays private in the development repository, which keeps serving the next major version's work.
- The rule against rewriting published history applies to the public repository from its first commit: whatever a gate finds there is fixed forward. The gates still examine everything that gets published: the snapshot commit passes the blocklist and gitleaks before it is pushed (docs/release-gate.md, "Release procedure").
- Nothing in the snapshot links into or depends on the private repository; its name appears only in the release procedure's historical steps. Commit SHAs and CI figures measured there are labelled "development repository".
- This addendum supersedes the first sentence of "Consequences" above: making the repository public is not a settings change on the development repository but the snapshot publication in the release procedure.
