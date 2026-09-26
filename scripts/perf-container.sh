#!/usr/bin/env bash
# Builds a short-lived, production-mode Docker container for load testing (used to
# measure the "Capacity" table in docs/quality.md). Host-neutral: no hostnames,
# ports or credentials are baked in — everything comes from arguments/env vars, and
# the container is meant to be removed (`docker rm -f`) right after the run.
#
# What "production-mode" means here, matching the runbook (docs/deployment-cpanel.md
# SS3): code copied into the image's own filesystem (not bind-mounted — the dev
# stack's bind mount is itself a major source of slowdown a real deploy never has),
# APP_ENV=production, APP_DEBUG=false, config/route/event caches built,
# opcache.validate_timestamps=0.
#
# Usage:
#   bash scripts/perf-container.sh <container-name> <host-port> [source-dir] [--with-db]
#
# <source-dir> defaults to the current directory — pass a git worktree of an older
# commit to measure that version instead (see docs/quality.md "Capacity" for the
# full before/after recipe). The container joins docker network $NETWORK so it can
# reach a sibling MariaDB container the same way the dev stack does; override
# $IMAGE/$CPUS/$MEMORY/$NETWORK as needed.
#
# --with-db starts a FRESH sibling MariaDB 10.5 container ("<container-name>-db",
# same credentials the dev stack uses), points the *container's own copy* of .env at
# it (DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD — never the source directory's real
# .env on the host), waits for it to accept connections, then runs `migrate --force`
# and seeds `ExampleDataSeeder`. Use this for a commit whose schema differs from
# whatever the dev stack's own database is already migrated to (e.g. measuring an
# older commit as a "Before" baseline) — see docs/quality.md "Capacity" for why the
# "After" measurement there instead shares the dev stack's already-running database.
# Without --with-db, the container uses whatever DB_HOST/DB_DATABASE the source
# directory's own .env already specifies (typically the dev stack's database) and
# does not migrate/seed anything.
#
# The script only builds, starts and (with --with-db) migrates/seeds the container;
# running the load itself and tearing the container(s) down again
# ("docker rm -f <container-name> [<container-name>-db]") are the caller's job, same
# as any other short-lived fixture.
set -euo pipefail

name="${1:?usage: bash scripts/perf-container.sh <container-name> <host-port> [source-dir] [--with-db]}"
port="${2:?usage: bash scripts/perf-container.sh <container-name> <host-port> [source-dir] [--with-db]}"
src="."
with_db=0
for arg in "${@:3}"; do
  case "$arg" in
    --with-db) with_db=1 ;;
    *) src="$arg" ;;
  esac
done

image="${IMAGE:-vehicle-data-api-app:latest}"
cpus="${CPUS:-2}"
memory="${MEMORY:-2g}"
network="${NETWORK:-vehicle-data-api_default}"

[ -f "$src/.env" ] || { echo "perf-container: $src/.env is missing — copy .env.example to .env there first (and set APP_KEY)" >&2; exit 1; }

wait_for() {
  # $1: description, $2: check command (eval'd), $3: max seconds (default 30)
  local desc="$1" check="$2" max="${3:-30}" i=0
  until eval "$check" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge "$max" ]; then
      echo "perf-container: timed out waiting for $desc" >&2
      return 1
    fi
    sleep 1
  done
}

db_name=""
if [ "$with_db" = "1" ]; then
  db_name="${name}-db"
  docker rm -f "$db_name" >/dev/null 2>&1 || true
  docker run -d --name "$db_name" --network "$network" \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=vehicle_data \
    -e MARIADB_USER=vehicle_data -e MARIADB_PASSWORD=vehicle_data \
    mariadb:10.5 >/dev/null
  echo "perf-container: waiting for $db_name to accept connections..."
  wait_for "$db_name" "docker exec '$db_name' mysqladmin ping -h 127.0.0.1 -uroot -proot" 60
fi

tar_dir="$(mktemp -d)"
trap 'rm -rf "$tar_dir"' EXIT
tar --exclude=./node_modules --exclude=./.git --exclude=./playwright-report --exclude=./test-results \
    --exclude='./storage/logs/*' -C "$src" -cf "$tar_dir/app.tar" .

docker rm -f "$name" >/dev/null 2>&1 || true
docker create --name "$name" --network "$network" -p "${port}:80" --cpus="$cpus" --memory="$memory" "$image" >/dev/null
docker cp "$tar_dir/app.tar" "$name:/tmp/app.tar"
docker start "$name" >/dev/null

echo "perf-container: waiting for $name's web server to start listening..."
# Any response at all (even a 500, e.g. before migrate/seed with --with-db) is enough
# here — this only confirms openlitespeed itself has come up before the docker exec
# block below reaches it, not that the app is fully functional yet.
wait_for "$name" "curl -s -o /dev/null http://localhost:$port/" 30

db_sed=""
if [ "$with_db" = "1" ]; then
  db_sed="-e s/^DB_HOST=.*/DB_HOST=${db_name}/ -e s/^DB_DATABASE=.*/DB_DATABASE=vehicle_data/ -e s/^DB_USERNAME=.*/DB_USERNAME=vehicle_data/ -e s/^DB_PASSWORD=.*/DB_PASSWORD=vehicle_data/"
fi

docker exec "$name" sh -c "
  set -e
  cd /var/www/vhosts/localhost/html
  tar -xf /tmp/app.tar
  sed -i -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' -e 's/^LOG_LEVEL=.*/LOG_LEVEL=warning/' $db_sed .env
  php artisan config:cache -q
  php artisan route:cache -q
  php artisan event:cache -q
  chown -R nobody:nogroup storage bootstrap/cache
  echo 'opcache.validate_timestamps=0' >> /usr/local/lsws/lsphp84/etc/php/8.4/mods-available/99-vehicle-data.ini
  /usr/local/lsws/bin/lswsctrl restart
"

if [ "$with_db" = "1" ]; then
  echo "perf-container: migrating and seeding $db_name..."
  docker exec "$name" sh -c 'cd /var/www/vhosts/localhost/html && php artisan migrate --force -q && php artisan db:seed --class="VehicleData\Core\Database\Seeders\ExampleDataSeeder" --force -q'
fi

echo "perf-container: $name is up on http://localhost:$port (production mode, code copied in, opcache.validate_timestamps=0)"
[ -n "$db_name" ] && echo "perf-container: $db_name is its dedicated database"
echo "perf-container: remove it when done — docker rm -f $name${db_name:+ $db_name}"
