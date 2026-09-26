#!/usr/bin/env bash
# Refuses the em dash (U+2014) in tracked text: write a hyphen (-) instead.
# With --staged it checks only the lines a commit adds (the pre-commit hook); without it,
# every tracked file (CI). The committed fixtures are exempt: they are third-party source
# data, kept byte for byte so their documented checksums hold.
set -euo pipefail

dash=$(printf '\342\200\224')
fixtures=':!packages/vehicle-data-core/database/fixtures'

if [ "${1:-}" = "--staged" ]; then
  if git diff --cached -U0 --no-color -- . "$fixtures" | grep -v '^+++' | grep '^+' | grep -F "$dash"; then
    echo "refusing: an added line contains an em dash (U+2014); use a hyphen (-)"
    exit 1
  fi
else
  if git grep -n -F "$dash" -- . "$fixtures"; then
    echo "em dash (U+2014) found in the lines above; use a hyphen (-)"
    exit 1
  fi
fi
