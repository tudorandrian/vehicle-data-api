#!/bin/sh
# vehicle-data-api - make the two Laravel write-paths writable by the lsphp
# runtime user (nobody:nogroup, per httpd_config.conf) before handing off to
# the image's own entrypoint. Runs as root (the container's default user),
# scoped to exactly these two trees - no other host-tracked file is touched,
# and nothing here is committed to git as executable.
set -e

for dir in \
  /var/www/vhosts/localhost/html/storage \
  /var/www/vhosts/localhost/html/bootstrap/cache
do
  if [ -d "$dir" ]; then
    chown -R nobody:nogroup "$dir" 2>/dev/null || true
    chmod -R ug+rwX "$dir" 2>/dev/null || true
  fi
done

exec /entrypoint.sh "$@"
