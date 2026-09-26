#!/usr/bin/env bash
# Zero-downtime-ish release switch for a cPanel account (or any POSIX host):
# releases/<ts> + current symlink + shared/.env, shared/storage.
#
# Order: down --secret -> migrate --force -> switch release
# symlink -> config:cache/route:cache -> up. Maintenance mode is entered BEFORE
# migrating (against the release that is about to become current) so schema
# changes never run underneath live traffic, and the symlink swap + cache
# warm-up happen while the site is still down. If anything fails after `down`
# - including this script itself being interrupted (Ctrl-C, a CI job
# cancellation, a killed SSH session) - the trap below restores the previous
# release and always brings the site back `up`, so a failed or interrupted
# deploy never leaves the site stuck in maintenance. If the trap itself cannot
# recover (e.g. artisan is unreachable), the manual rollback documented in
# docs/deployment-cpanel.md still applies:
#   ln -sfn "$DEPLOY_TARGET/releases/<previous>" "$DEPLOY_TARGET/current" && "$PHP_BIN" artisan up
set -euo pipefail

: "${RELEASE_TAR:?path to release.tar.gz}"
: "${DEPLOY_TARGET:?base directory, e.g. \$HOME/apps/platform}"
PHP_BIN="${PHP_BIN:-php}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
DEPLOY_SECRET="${DEPLOY_SECRET:-}"
SMOKE_URL="${SMOKE_URL:-}"
SMOKE_KEY="${SMOKE_KEY:-}"

# PID suffix: two deploys started within the same UTC second (e.g. a quick
# retrigger, or the two dry-run deploys in ci.yml's deploy-dry-run job) must
# never collide on the same releases/<ts> directory.
ts="$(date -u +%Y%m%d%H%M%S)-$$"
releases="$DEPLOY_TARGET/releases"; shared="$DEPLOY_TARGET/shared"; current="$DEPLOY_TARGET/current"; new="$releases/$ts"
mkdir -p "$releases" "$shared/storage/app/imports" "$shared/storage/framework/"{cache,sessions,views} "$shared/storage/logs"

[ -f "$shared/.env" ] || { echo "deploy: $shared/.env is missing"; exit 1; }
grep -Eq '^APP_KEY=base64:.+' "$shared/.env" || { echo "deploy: APP_KEY missing in shared/.env - refusing"; exit 1; }
grep -Eq '^APP_DEBUG=false' "$shared/.env" || { echo "deploy: APP_DEBUG must be false - refusing"; exit 1; }

mkdir -p "$new" && tar -xzf "$RELEASE_TAR" -C "$new"
rm -rf "$new/storage" && ln -s "$shared/storage" "$new/storage"
ln -sf "$shared/.env" "$new/.env"

previous=""
[ -L "$current" ] && previous=$(readlink -f "$current")

# From the moment the (previous) release is asked to go into maintenance
# mode, a failure - or this script being killed outright - must not leave
# the site stuck down or pointed at a half-deployed release.
site_down=0
restored=0

bring_back_up() {
  trap - ERR HUP INT TERM
  if [ "$site_down" = "1" ] && [ "$restored" = "0" ] && [ -n "$previous" ]; then
    echo "deploy: failed - restoring previous release" >&2
    ln -sfn "$previous" "$current.tmp" && mv -T "$current.tmp" "$current"
    restored=1
  fi
  if [ "$site_down" = "1" ]; then
    (cd "$current" 2>/dev/null && "$PHP_BIN" artisan up) || echo "deploy: could not bring the site back up automatically - run '$PHP_BIN artisan up' in $current by hand" >&2
  fi
  exit 1
}
# HUP/INT/TERM: this script may be killed outright (Ctrl-C, a cancelled CI
# job, a dropped SSH session) - the same recovery must run, not just on a
# command failure.
trap bring_back_up ERR HUP INT TERM

down_args=(down --retry=5)
[ -n "$DEPLOY_SECRET" ] && down_args+=(--secret="$DEPLOY_SECRET")

# OPcache's shared-memory bytecode cache is per host, not per PHP-FPM/LSPHP
# worker - one HTTP request to a reset endpoint clears it for every worker.
# With opcache.validate_timestamps=0 (docs/deployment-cpanel.md SS3) this is
# what actually makes a release take effect; PHP never rechecks file mtimes on
# its own once that setting is off. OPCACHE_RESET_URL is an operator-provided
# endpoint (never committed here - no hostnames in this repo) that calls
# opcache_reset() and responds. Defined early (before first use) so both the
# main flow and rollback_and_fail() below can call it.
OPCACHE_RESET_URL="${OPCACHE_RESET_URL:-}"
reset_opcache() {
  if [ -z "$OPCACHE_RESET_URL" ]; then
    # A warning, not just information: this script has no way to check the
    # live opcache.validate_timestamps setting, and leaving it at 0 with
    # OPCACHE_RESET_URL unset means every future release silently keeps
    # serving the previous one's bytecode forever (docs/deployment-cpanel.md
    # SS3). Harmless noise if validate_timestamps is actually 1.
    echo "deploy: WARNING - OPCACHE_RESET_URL not set; skipping OPcache reset. This is only safe if opcache.validate_timestamps=1 - with 0, releases silently stop taking effect (see docs/deployment-cpanel.md SS3)" >&2
    return 0
  fi
  if curl -sf --max-time 10 "$OPCACHE_RESET_URL" >/dev/null; then
    echo "deploy: OPcache reset requested at OPCACHE_RESET_URL"
    return 0
  fi
  echo "deploy: OPcache reset request to OPCACHE_RESET_URL failed" >&2
  return 1
}

rollback_and_fail() {
  echo "deploy: smoke failed - rolling back" >&2
  if [ -n "$previous" ]; then
    site_down=1
    (cd "$current" && "$PHP_BIN" artisan "${down_args[@]}" || true)
    ln -sfn "$previous" "$current.tmp" && mv -T "$current.tmp" "$current"
    (cd "$current" && "$PHP_BIN" artisan up || true)
    site_down=0
    # Best effort: the rollback already failed the deploy, so a second OPcache
    # failure here must not mask that with a different exit path - || true.
    # Without this, the previous release's bytecode could stay evicted/stale
    # in the same shared cache the bad release just warmed up.
    reset_opcache || true
  fi
  exit 1
}

if [ -n "$previous" ]; then
  # Set before calling `artisan down`: if `down` itself is what fails (or
  # this script is killed mid-call), the site may already be down and the
  # trap must still attempt recovery rather than assume it never started.
  site_down=1
  (cd "$previous" && "$PHP_BIN" artisan "${down_args[@]}")
fi

(cd "$new" && "$PHP_BIN" artisan migrate --force --no-interaction)

ln -sfn "$new" "$current.tmp" && mv -T "$current.tmp" "$current"

(cd "$current" && "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan event:cache && "$PHP_BIN" artisan view:clear)

(cd "$current" && "$PHP_BIN" artisan up)
site_down=0

# A reset that was explicitly requested (OPCACHE_RESET_URL set) but failed is
# fatal here, not merely logged: the smoke check below would otherwise run
# against a worker still serving the PREVIOUS release's bytecode (OPcache
# hasn't noticed the swap because validate_timestamps=0 means it never checks
# file mtimes on its own) and could pass while reporting success for a
# release that was never actually served. An unset OPCACHE_RESET_URL is not
# an error - see reset_opcache()'s own message - and never reaches this check.
if ! reset_opcache && [ -n "$OPCACHE_RESET_URL" ]; then
  echo "deploy: OPCACHE_RESET_URL was set but the reset failed - refusing to let the smoke check pass against stale bytecode" >&2
  rollback_and_fail
fi

# curl piped straight into grep -q would let a curl failure (bad host,
# timeout, connection refused) silently read as "pattern not found" - and
# under `pipefail` a SIGPIPE from grep -q closing its input early can also
# make curl itself report an error unrelated to the actual response. Capture
# the body first so a transport failure and a content mismatch are both
# handled by the same explicit `|| rollback_and_fail`, with a clear log line
# either way.
smoke_check() {
  local url="$1" pattern="$2" body
  shift 2
  if ! body=$(curl -sf --max-time 20 "$@" "$url"); then
    echo "deploy: smoke request to $url failed" >&2
    return 1
  fi
  if ! grep -q -- "$pattern" <<<"$body"; then
    echo "deploy: smoke response from $url did not match $pattern" >&2
    return 1
  fi
}

if [ -n "$SMOKE_URL" ]; then
  smoke_check "$SMOKE_URL/v1/health" '"status":"ok"' || rollback_and_fail
  if [ -n "$SMOKE_KEY" ]; then
    smoke_check "$SMOKE_URL/v1/taxonomies" '"data"' -H "Authorization: Bearer $SMOKE_KEY" || rollback_and_fail
  fi
fi

# Housekeeping only: a failure here must not undo an already-live deploy.
# NUL-delimited throughout (find -print0 / sort -z / cut -z / xargs -0), not
# `ls | tail | xargs`, so a release directory name is never split or
# misparsed even in principle (release dirs are always "<ts>-<pid>", but this
# is the robust way to enumerate/sort/delete arbitrary file names by mtime).
find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\0' 2>/dev/null \
  | sort -znr | cut -z -d' ' -f2- | tail -z -n +$((KEEP_RELEASES + 1)) \
  | xargs -0 -r rm -rf || true

echo "deploy: release $ts is live"
