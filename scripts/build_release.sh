#!/usr/bin/env bash
# Packages a release tarball from the current working tree. Callers are
# responsible for installing production dependencies first
# (`composer install --no-dev --optimize-autoloader`, `npm ci --ignore-scripts
# && npm run postinstall`) - this script only owns the tar packaging so
# it is not duplicated between ci.yml's build job and deploy.yml.
set -euo pipefail

OUT="${1:-release.tar.gz}"

# Write the archive to a temp file OUTSIDE the tree being archived, then move
# it into place. Writing straight to "$OUT" inside "." makes the top-level
# directory's mtime change while tar is still walking it (a new dirent
# appears mid-read), which GNU tar reports as "tar: .: file changed as we
# read it" and exits non-zero; --exclude="$OUT" keeps the *content* out of
# the archive but does not stop the directory mtime from changing.
tmp="$(mktemp)"
# GNU tar has no "exclude, but re-include this one match" operator, so build
# an explicit exclude-from list for the dotenv files instead of a single
# wildcard: `.env`/`.env.local`/`.env.testing`/... never belong in a release
# artifact (they either don't exist in a clean checkout or would leak
# environment-specific values), but `.env.example` - committed, secret-free -
# is exactly the kind of thing worth keeping (e.g. `docs/deployment-cpanel.md`
# and `composer.json`'s `setup` script both read it).
env_excludes="$(mktemp)"
{
  echo './.env'
  find . -maxdepth 1 -name '.env.*' ! -name '.env.example' -printf './%f\n'
} > "$env_excludes"
trap 'rm -f "$tmp" "$env_excludes"' EXIT

tar -czf "$tmp" \
  --exclude=.git \
  --exclude=node_modules \
  --exclude=tests \
  --exclude=storage/logs \
  --exclude-from="$env_excludes" \
  --exclude=storage/framework/cache/* \
  --exclude=storage/framework/sessions/* \
  --exclude=storage/framework/views/* \
  --exclude=storage/app/* \
  .

mv "$tmp" "$OUT"
rm -f "$env_excludes"
trap - EXIT

echo "build_release: wrote $OUT"
