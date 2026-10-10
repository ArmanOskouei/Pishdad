# Alpine + prebuilt php85 packages: zero compilation, zero Debian mirror.
# (deb.debian.org is unreachable/slow from this network; dl-cdn.alpinelinux.org is fast.)
#
# 8.3 → 8.4 → 8.5, deliberately in two hops. 8.3 was the oldest branch Laravel
# 13 accepts (php ^8.3) and its security support ends around Nov 2026, so
# staying would have meant losing fixes. Laravel 13's own constraint is
# `^8.3` with no upper bound, so 8.5 is permitted.
#
# The `/etc/phpNN/` paths below must move with the package rename. If they
# don't, the COPY lands in a directory nothing reads and the production
# php.ini is dropped without any error.
FROM alpine:3.24

# php-fpm + every extension Laravel 13 / Sanctum / packages need, all prebuilt.
#
# opcache: there is deliberately NO `php85-opcache` package here — Alpine 3.24
# has no such package (`apk add php85-opcache` fails the whole layer). Zend
# OPcache is nevertheless active in the runtime (`php -m` lists it; it ships
# inside the `php85` package on 3.24), so this comment exists to stop the next
# reader from "fixing" the omission the way this file's author did once already.
# Tuning (validate_timestamps=0, JIT) belongs to the production image, not
# here: this one runs on a bind-mount where code changes must be visible
# without a rebuild.
#
# postgresql18-client: BackupRunner shells out to `pg_dump` from PATH
# (config('backup.binary')). The PHP `pgsql` extension is NOT that binary, so
# without this package the daily `backup:run` at 03:15 fails silently on
# every install — the highest-severity gap this file had. Verified available
# in alpine:3.24 (`docker run --rm alpine:3.24 apk add postgresql18-client`).
# Version must track docker/postgres/Dockerfile's server major (currently 18).
#
# Two naming traps in 8.5, both of which cost a failed build here:
#   - the CLI package is `php85`, not `php85-cli`, and it already ships
#     /usr/bin/php. The `ln -s /usr/bin/php85 /usr/bin/php` carried over from
#     8.3/8.4 would shadow the real binary with a symlink to itself.
#   - `php85-fpm` provides /usr/sbin/php-fpm85 but no `php` binary at all, so
#     installing it alone yields a layer that builds and then cannot run
#     anything.
RUN apk add --no-cache \
    php85 php85-fpm \
    php85-ctype php85-curl php85-dom php85-fileinfo php85-mbstring \
    php85-openssl php85-pdo php85-pdo_pgsql php85-pgsql php85-phar \
    php85-session php85-tokenizer php85-xml php85-simplexml php85-xmlwriter php85-xmlreader \
    php85-intl php85-bcmath php85-sockets php85-pcntl php85-zip \
    php85-pecl-redis php85-iconv \
    postgresql18-client \
    && ln -s /usr/sbin/php-fpm85 /usr/sbin/php-fpm

# Composer binary (image already pulled; no network cost at build)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Leverage layer cache: install deps first, then copy the rest on build
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts || true

COPY . .

# Local dev php overrides (php85 reads /etc/php85/conf.d/*.ini)
# The path must move with the package rename, otherwise the COPY lands in a
# directory nothing reads and the production php.ini is silently dropped.
COPY docker/php/php.ini /etc/php85/conf.d/99-local.ini
# FPM pool: listen on 9000 for the nginx service (last value wins over www.conf)
COPY docker/php/zz-docker.conf /etc/php85/php-fpm.d/zz-docker.conf

# Entrypoint: wait for db, ensure vendor, fix perms, run php-fpm
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
