# Deploying to cPanel shared hosting

Generic runbook for running this app on a cPanel account (or any shared host
with SSH + MultiPHP + cron). No hostnames, IPs, account names or
home-directory user names appear below - replace every `<placeholder>`
before use.

## 1. Prerequisites

- A cPanel account with SSH access enabled.
- MultiPHP Manager, able to select PHP 8.4 (`ea-php84`) for a subdomain.
- Cron Jobs available in cPanel.
- MariaDB 10.5 or later (10.11 recommended; both tested in CI) - a cPanel MySQL database, despite the "MySQL" naming.
- A subdomain dedicated to the API, e.g. `api.<your-domain>`.
- A CDN/WAF (e.g. Cloudflare) proxying the subdomain.

## 2. Layout

```
~/apps/platform/
├── releases/<timestamp>/   # one directory per deploy, created by scripts/deploy.sh
├── current -> releases/<timestamp>/   # atomic symlink, always points at the live release
└── shared/
    ├── .env                # the one persistent, never-overwritten config file
    └── storage/            # framework cache/sessions/views/logs, app/imports
```

When creating the subdomain in cPanel, set its document root to
`~/apps/platform/current/public`. Laravel's `public/.htaccess` handles the
front-controller rewrites on both LiteSpeed and Apache - no extra rewrite
rules are needed. In MultiPHP Manager, assign `ea-php84` to the subdomain.

## 3. Shared `.env` template

Create `~/apps/platform/shared/.env` once, by hand, before the first deploy.
It uses the same keys as `.env.example` with production values:

```
APP_NAME=vehicle-data-api
APP_ENV=production
APP_KEY=                      # generate below, never commit or reuse across environments
APP_DEBUG=false
APP_URL=https://<api-subdomain>   # in production the only Host the app accepts (any other answers 400), so DEPLOY_SMOKE_URL uses it too
APP_VERSION=                  # optional; set to a release tag/SHA so GET /v1/health reports it (config-cached)
APP_LOCALE=ro
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=info                # spec §9 requires the per-request JSON log line, which is logged at info; warning would silently drop it
LOG_DAILY_DAYS=14
LOG_FILE_PERMISSION=0664         # one account owns every file on shared hosting

DB_CONNECTION=mariadb
DB_HOST=<cpanel-mysql-host, usually localhost>
DB_PORT=3306
DB_DATABASE=<cpanel-database-name>
DB_USERNAME=<cpanel-database-user>
DB_PASSWORD=<cpanel-database-password>

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=array
# No Redis on shared hosting: cache/queue/session all ride on the same MariaDB
# database via Laravel's database drivers.

CORE_TRUSTED_PROXIES=<the CDN's published IPv4/IPv6 ranges, comma-separated>
CORE_MAX_BODY_BYTES=65536
CORE_IMPORT_REJECT_SHARE=0.05
CORE_IMPORT_REJECT_SHARE_WMI=1.0     # by design; after a wmi import check its reject count (docs/known-limitations.md, R11)
CORE_EEA_COUNTRY=RO
CORE_DOCS_TRY_IT_KEY=          # PUBLIC (rendered into /docs): catalogue:read-only, low rate and quota; an origin allow-list only stops browser callers, not a security boundary on its own
CORE_AUTH_FAIL_PER_MINUTE=30
CORE_REQUEST_RETENTION_DAYS=90 # see §8a

SENTRY_LARAVEL_DSN=            # optional
SENTRY_TRACES_SAMPLE_RATE=0.1
```

Generate `APP_KEY` locally (never on the server, never committed):

```bash
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

`scripts/deploy.sh` refuses to run if `APP_KEY` is missing from
`shared/.env` or if `APP_DEBUG` is not exactly `false`.

The Sentry Laravel SDK (`sentry/sentry-laravel`, configured in `config/sentry.php`)
reads `SENTRY_LARAVEL_DSN`, not `SENTRY_DSN`.

In cPanel's MultiPHP INI Editor for the subdomain, set `opcache.validate_timestamps=0`
(`opcache.revalidate_freq` is then irrelevant): releases are atomic directory switches
(`scripts/deploy.sh` symlinks `current` to a new `releases/<timestamp>` directory), so
PHP never needs to stat files to notice a change, and OPcache can trust its compiled
cache for the whole life of the worker. This is a production-only setting - the local
Docker stack's `docker/openlitespeed/99-vehicle-data.ini` keeps it at `1` because that
tree is a bind mount that changes under the running server as you edit files.

**`validate_timestamps=0` and `OPCACHE_RESET_URL` are a pair, not two independent
options: setting the former without configuring the latter in the deploy environment
means every future release silently keeps serving the previous one's bytecode**, since
nothing left in the request path ever tells PHP to look again. Set both together, or
neither - see immediately below for what `OPCACHE_RESET_URL` needs to point at.

**With `validate_timestamps=0`, a release only takes effect once OPcache is actually
cleared** - PHP stops rechecking file mtimes altogether, so a worker holding the
previous release's compiled bytecode keeps serving it until that shared cache is reset
(OPcache's bytecode cache lives in memory shared across every PHP-FPM/LSPHP worker on
the host, so one reset clears it for all of them). `scripts/deploy.sh` requests this
automatically after the symlink swap if `OPCACHE_RESET_URL` is set in the deploy
environment - point it at an operator-provided endpoint that calls `opcache_reset()`
(set up on the host, never committed to this repository); leave it unset and the step
is skipped with a log line, which is fine only if `validate_timestamps` stays `1`.
**If `OPCACHE_RESET_URL` is set and the reset request fails, the deploy fails and rolls
back** (the same path as a failed smoke check) rather than letting the smoke check pass
against a worker that is still serving the previous release's bytecode; `deploy.sh`
also retries the reset (best effort, `|| true`) when it rolls back for any other reason,
so the previous release's bytecode isn't left stale either. Separately, PHP's own
realpath cache (`realpath_cache_ttl`, 120 s by default) can keep
resolving `current` to the *previous* release's real path for up to that window after
the swap, independent of OPcache - a long-lived worker process that already resolved a
file under the old `current` target before the swap may keep reading from there until
either that TTL expires or the worker recycles.

## 4. Cron

Add exactly one cron job in cPanel - Laravel's scheduler (`routes/console.php`)
drives everything else: the queue worker, the daily usage rollup/IP purge/prune
(`vehicle:usage aggregate`/`purge-ip`/`prune`), the daily cache gc
(`vehicle:cache gc`), the daily dated import/status log prune
(`vehicle:logs prune`), the weekly Wikidata refresh and the weekly status
report - see §8a for what each retention job deletes and when:

```
* * * * * /usr/local/bin/ea-php84 $HOME/apps/platform/current/artisan schedule:run >> $HOME/apps/platform/shared/storage/logs/cron.log 2>&1
```

## 5. First release, by hand

1. Download `release.tar.gz` from the `build` job of a green CI run (or a
   tagged `deploy` run) on GitHub Actions.
2. `scp` it to the server, e.g. `scp release.tar.gz <user>@<host>:~/release.tar.gz`.
3. `scripts/deploy.sh` is *inside* that tarball (it's part of the repo checkout
   the `build` job packaged) - there is nothing named `scripts/deploy.sh` on
   the server yet, so extract just that one file from the tarball before
   running it:
   ```bash
   tar -xzf ~/release.tar.gz -O ./scripts/deploy.sh > ~/deploy.sh
   ```
4. Run that extracted copy over SSH. Add `OPCACHE_RESET_URL=<endpoint>` only if the subdomain's `opcache.validate_timestamps` is `0` (§3 above says what that endpoint needs to do); omit the line entirely otherwise:
   ```bash
   RELEASE_TAR=$HOME/release.tar.gz \
   DEPLOY_TARGET=$HOME/apps/platform \
   PHP_BIN=/usr/local/bin/ea-php84 \
   OPCACHE_RESET_URL=<endpoint> \
   bash ~/deploy.sh
   ```
5. Create the first API client:
   ```bash
   /usr/local/bin/ea-php84 $HOME/apps/platform/current/artisan vehicle:client create --name=<owner-name> --owner=<owner-name> --scopes=catalogue:read,vin:decode,snapshot:read
   ```
6. Run the yearly imports once, on demand (they are not scheduled):
   ```bash
   /usr/local/bin/ea-php84 $HOME/apps/platform/current/artisan vehicle:import eea --year=2024
   /usr/local/bin/ea-php84 $HOME/apps/platform/current/artisan vehicle:import ro-fleet --year=2025
   /usr/local/bin/ea-php84 $HOME/apps/platform/current/artisan vehicle:import wikidata
   /usr/local/bin/ea-php84 $HOME/apps/platform/current/artisan vehicle:import wmi
   ```

Every later release is just: upload the new `release.tar.gz`, re-extract
`scripts/deploy.sh` from it the same way (step 3 - it may have changed
between releases), and re-run it (steps 2–4). See `.github/workflows/deploy.yml`
for the automated version once secrets are configured (§6).

## 6. GitHub secrets for `deploy.yml`

Set these repository (or environment) secrets to enable the automated
`deploy` job in `.github/workflows/deploy.yml` - none of them exist in this
repository; an owner with admin access adds them to the repository that deploys:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | the server hostname/IP |
| `DEPLOY_PORT` | SSH port (22 if unset) |
| `DEPLOY_USER` | the cPanel SSH user |
| `DEPLOY_SSH_KEY` | private half of a **dedicated** ed25519 key added to cPanel → SSH Access → Manage SSH Keys (do not reuse a personal key) |
| `DEPLOY_KNOWN_HOSTS` | the server's SSH host key(s), pinned - see below |
| `DEPLOY_TARGET` | `/home/<user>/apps/platform` |
| `DEPLOY_PHP_BIN` | `/usr/local/bin/ea-php84` |
| `DEPLOY_SECRET` | a random string used as the maintenance-mode bypass token |
| `DEPLOY_SMOKE_URL` | `https://<api-subdomain>` |
| `DEPLOY_SMOKE_KEY` | a client created with `--scopes=catalogue:read` only, used solely for the post-deploy smoke check |
| `OPCACHE_RESET_URL` | optional; the operator-provided endpoint that calls `opcache_reset()` (§3) - required only if the subdomain's `opcache.validate_timestamps` is set to `0`. Leave unset (and leave `validate_timestamps` at `1`) if you have not set this up. |

`DEPLOY_KNOWN_HOSTS` pins the server's host key so `deploy.yml` connects with
`ssh -o StrictHostKeyChecking=yes` instead of trusting whatever key the host
happens to present on that connection (the latter cannot detect a
man-in-the-middle on a first connection). Produce it **once**, from a trusted
network - e.g. the machine used to set up the server - and store the output
verbatim as the secret:
```bash
ssh-keyscan -p <port, default 22> <host>
```

Until `DEPLOY_HOST` is set, `deploy.yml`'s `deploy` job is skipped
unconditionally (its `check` job only flips `enabled` to `true` once that
secret exists) - pushing a `v*` tag or dispatching the workflow in this
repository is a no-op today.

## 7. CDN/WAF

- Proxy: on for the API subdomain.
- SSL/TLS mode: Full (strict).
- WAF: managed rules enabled.
- Rate limiting: a rule on `/v1/*`, e.g. 300 requests/minute per IP.
- Cache rule: "bypass" for any request carrying an `Authorization` header
  (every authenticated response is already `Cache-Control: private` anyway).
- Disable script injection and auto-minify features on the API host - this is
  a JSON API plus a static docs bundle, not a page the CDN should rewrite.

## 8. Monitoring

- An external HTTP monitor on `https://<api-subdomain>/v1/health` every 5
  minutes, expecting `"status":"ok"`.
- The weekly `vehicle:status` command (already scheduled, §4) writes to
  `shared/storage/logs/status-YYYY-MM-DD.log` - review it, or ship it to
  Sentry/log aggregation if desired.
- `SENTRY_LARAVEL_DSN` in `shared/.env` (§3) is optional; leave it empty until
  outbound event and trace sanitization is verified. `send_default_pii=false`
  still permits URLs containing VINs and other event context; see SECURITY.md.

## 8a. Retention

| Data | Where | Kept | Deleted by |
|---|---|---|---|
| Application log (JSON, one file per day) | `shared/storage/logs/laravel-YYYY-MM-DD.log` | `LOG_DAILY_DAYS` (14) | Monolog, when a new day's file starts |
| Security events (`api.auth.failed`, `api.auth.blocked`, key prefix only, no IP) | same file | same 14 days | same |
| Raw requests (`vd_api_requests`: route name, status, duration, client, IP) | database | `CORE_REQUEST_RETENTION_DAYS` (90); the IP column is nulled after 30 days | `vehicle:usage purge-ip` 00:20 UTC, `vehicle:usage prune` 00:30 UTC |
| Daily usage aggregate (`vd_api_usage_daily`) | database | indefinitely (counts only) | never |
| Rate-limit, quota and failed-authentication counters (`vd_api_counters`: `ThrottleClient` per-minute rate and daily quota, `AuthenticateClient` failed attempts per IP) | database (`vd_api_counters`) | until one day after the window ends (+ up to one day; a caller IP may sit in the `ip:<address>` scope until then) | `vehicle:usage prune` 00:30 UTC (via `PruneRequests`) |
| Cache rows (also holds resolved clients, keyed by the SHA-256 hash of the key - never the key itself - for at most 60 s) | `cache` table | until expiry (+ up to one day) | `vehicle:cache gc` 00:40 UTC |
| Import and status output | `shared/storage/logs/imports-YYYY-MM-DD.log`, `status-YYYY-MM-DD.log` | `LOG_DAILY_DAYS` | `vehicle:logs prune` 00:50 UTC |
| `schedule:run` output (`cron.log`, §4) | host, outside the application | grows without bound | operator rotates or truncates it periodically |
| Web-server access log | host, outside the application | host setting | host |
| Sentry events | Sentry, `send_default_pii=false` | account setting | Sentry |

The web-server access log records the full request line, so `GET /v1/vin/<VIN>` lands there even though the application logs only the route name. A VIN can identify a person's vehicle. Set the host's access-log retention to the shortest value the panel allows, disable access logging for the API vhost if the panel offers it, and say so in your privacy notice. The application's own retention above is what this repository can promise.

Deployments from before this retention scheme may still have undated `shared/storage/logs/imports.log` and `shared/storage/logs/status.log` files; `vehicle:logs prune` only recognises the dated `imports-YYYY-MM-DD.log`/`status-YYYY-MM-DD.log` names, so it never prunes these - delete them once by hand.

## 9. Rollback

`scripts/deploy.sh` already rolls back automatically on migration failure or
a failed post-deploy smoke check - and on the deploy being interrupted
outright (Ctrl-C, a cancelled CI job, a dropped SSH session): its trap on
`HUP`/`INT`/`TERM` runs the same recovery and always finishes with
`artisan up`, so an interrupted deploy resolves to "site back up on whichever
release was live before" rather than staying down. If a bad release still
needs a manual rollback (e.g. a problem only shows up after human testing):

```bash
ln -sfn ~/apps/platform/releases/<previous-timestamp> ~/apps/platform/current.tmp
mv -T ~/apps/platform/current.tmp ~/apps/platform/current
/usr/local/bin/ea-php84 ~/apps/platform/current/artisan up
```
(the intermediate `current.tmp` + `mv -T` - the same two steps `scripts/deploy.sh`
itself uses - keeps the switch atomic: anything reading the `current` symlink
mid-command always sees either the old or the new target, never a moment
where the symlink doesn't exist.)

Migrations in this project are additive by rule (CONTRIBUTING.md, "Evolution
rules"; ADR 0004) - no down-migration is ever run as part of rollback.

The rolled-back release still has whatever `config:cache`/`route:cache` it
was originally deployed with - if `shared/.env` changed *after* that release
was live (e.g. rotating a key, changing a `CORE_*` setting), re-run
`artisan config:cache` (and `route:cache`) once rollback completes, from
`~/apps/platform/current`, so the rolled-back release picks up the current
`.env` rather than the values it shipped with.

Since a bad `production` deploy is exactly what this rollback exists for,
consider adding GitHub environment protection rules to the `production`
environment `deploy.yml`'s `deploy` job targets (Settings → Environments →
`production`) - e.g. required reviewers before a tag-triggered deploy runs,
or restricting which branches/tags can deploy to it at all.

## 10. Twelve-factor note

Moving from cPanel to a VPS (or any other POSIX host) later changes only
three things: `shared/.env` (database/host-specific values), the one cron
line (§4), and the web server's document root configuration. `scripts/deploy.sh`
and the release layout (§2) are unchanged - this is exactly why
`.github/workflows/deploy.yml` is written as a reusable `workflow_call` with
an `app-path` input, so a future platform repository can call it unmodified.
