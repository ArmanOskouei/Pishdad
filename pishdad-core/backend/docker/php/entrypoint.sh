#!/bin/sh
# Container entrypoint: wait for Postgres, ensure vendor/, fix perms, run php-fpm.
set -e

DB_HOST="${DB_HOST:-db}"

echo "Waiting for Postgres at ${DB_HOST}:${DB_PORT:-5432}..."
until php -r '$h=getenv("DB_HOST");$p=getenv("DB_PORT");$u=getenv("DB_USERNAME");$w=getenv("DB_PASSWORD");try{new PDO("pgsql:host=$h;port=$p;connect_timeout=2",$u,$w);exit(0);}catch(Throwable $e){exit(1);}' >/dev/null 2>&1; do
  sleep 2
done
echo "Postgres is up."

# Fresh clone (no vendor/ on host): install inside the container.
if [ ! -f vendor/autoload.php ]; then
  echo "vendor/ missing — running composer install..."
  composer install --no-interaction --prefer-dist
fi

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

# -R: dev image runs FPM as root (see zz-docker.conf); production must use non-root.
# `php-fpm` (no version) is the symlink the Dockerfile points at whichever
# package is installed. Hardcoding php-fpmNN here made the 8.3 -> 8.4 bump fail
# with a restart loop on `php-fpm83: not found`, after the image had already
# built and looked fine.
#
# E76 — the scheduler service passes its own command (`schedule:work`); the
# wait/prepare steps above still run first (it needs DB + code too), then its
# command replaces php-fpm. No `command:` ⇒ the classic php-fpm server.
if [ "$#" -gt 0 ]; then
  exec "$@"
fi
exec php-fpm -F -R
