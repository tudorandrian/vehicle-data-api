# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | yes: security fixes are released as 1.x patch versions |
| < 1.0 | no |

## Reporting a vulnerability

Report privately through GitHub's private vulnerability reporting on this repository (Security tab → "Report a vulnerability"). Please do not open a public issue for a vulnerability. You will get a first response within 7 days, and a fix or a mitigation plan once the report is confirmed. Include the affected route or command, the request (with any key redacted) and what you observed.

## How keys are handled

- A key is `vd_live_` followed by 40 base62 characters, generated with a cryptographically secure random source by `php artisan vehicle:client create`, and printed once.
- Only the SHA-256 hash and an 8-character prefix are stored. A lookup finds the client by prefix and compares hashes in constant time; the clear key is never written to the database or the logs. Log lines identify a client by its key prefix only (a failed attempt logs the prefix only when the presented key is well formed, and never the caller's IP); usage rows in `vd_api_requests` store the client id and the caller's IP, which is purged after 30 days (`core.request_ip_retention_days`).
- `vehicle:client rotate --id=<id>` issues a new key and invalidates the old one immediately; `vehicle:client revoke --id=<id>` disables the client; `--expires` sets an expiry date at creation.
- Each key carries scopes (`catalogue:read`, `vin:decode`, `snapshot:read`), a per-minute rate limit, a daily quota and an allow-list of browser origins. Failed authentication is throttled per IP.
- The optional `CORE_DOCS_TRY_IT_KEY` is rendered into the public `/docs` page and is therefore **public**: anyone can see and use it. An origin allow-list only stops browser JavaScript running on another origin from using it — it is not a security boundary against a non-browser caller — so it must be a `catalogue:read`-only key with a low rate and quota.
- Continuous integration generates its test keys at job start; no key is ever committed, and gitleaks (with a rule for this key format) runs in the pre-commit hook and in CI.
- The application logs the route name (`v1.vin.show`), never the path, so a VIN never enters its logs; the web server in front of it may log full URLs under the operator's own retention (see the deployment runbook).

## Out of scope

- The accuracy, completeness or timeliness of the upstream open data (EEA, DRPCIV, Wikidata, NHTSA vPIC). Report data errors to the publisher; see `docs/data-sources.md`.
- The VIN endpoint is a structural decode only; it is not a registration, ownership or history lookup, and wrong model-year or plant guesses are not vulnerabilities.
- Denial of service by volume against a deployment: rate limits, quotas and an upstream CDN/WAF are the operator's controls (see `docs/deployment-cpanel.md`).
- Findings that require an already fully compromised server, database or operator account, without demonstrating a separate boundary failure.

Scope bypasses using a restricted key, use of a revoked credential, and accidental disclosure by the API or its telemetry are in scope. Possessing a key does not authorize access beyond that key's permissions.

## Monitoring privacy

The route-name-only `api.request` log is not a guarantee about exception logs or external telemetry. `send_default_pii=false` in Sentry does not remove request URLs, query strings, all headers, exception messages or arbitrary breadcrumbs. In particular, a VIN in a URL can be transmitted when a DSN is configured. Leave `SENTRY_LARAVEL_DSN` unset until the deployment has tested outbound event and trace sanitization, as described in [the architecture review](docs/architecture-review.md#r1--high-telemetry-privacy-is-not-established). No telemetry sanitization guarantee is made by this release candidate.
