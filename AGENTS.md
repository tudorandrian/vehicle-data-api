# Instructions for AI assistants

1. Read `CONTRIBUTING.md` and `docs/api.md` first. They hold the rules: field classes, evolution (additive in `/v1`, breaking only as `/v2`), the four extension contracts, test layers, the data and licence policy, and the publishable-commit rule.
2. The OpenAPI document is the source of truth for responses: `packages/vehicle-data-core/resources/openapi/openapi.yaml` (served assembled with every registered kind at `/openapi.yaml`). Change it, a contract test and `CHANGELOG.md` together with any response change; never let code and contract drift.
3. Run PHP only inside the `app` container. Before proposing a change, run and pass:
   - `docker compose exec -T app vendor/bin/pest --exclude-group=network --exclude-group=browser` (two separate flags)
   - `docker compose exec -T app vendor/bin/pint --test`
   - `docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G`
   - `npx spectral lint packages/vehicle-data-core/resources/openapi/openapi.yaml` when the contract changes
4. Never add data from a source that is not listed in `docs/data-sources.md`, never scrape, never hand-type vehicle data; every write goes through an importer with provenance.
5. Never commit an API key, a secret, an image, a file over 100 KB, or the name of a consumer site, company, client, partner, host or local machine. Never bypass the pre-commit hook.
6. Architectural decisions are recorded in `docs/adr/`; add an ADR when you make a new one.
