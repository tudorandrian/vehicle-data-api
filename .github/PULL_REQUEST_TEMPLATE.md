## What changed

## Tests run
- [ ] `docker compose exec -T app vendor/bin/pest --exclude-group=network --exclude-group=browser` green (paste the summary line)
- [ ] `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` clean

## Contract and docs impact
- [ ] No response change, or: `openapi.yaml` and a contract test updated, Spectral clean, `oasdiff` reviewed (see CONTRIBUTING)
- [ ] Docs updated where behaviour changed (`README.md`, `docs/api.md`, an ADR for a new decision)
- [ ] `CHANGELOG.md` entry under the unreleased section
- [ ] No new file over 100 KB, no image, no secret (the pre-commit hook passed)
