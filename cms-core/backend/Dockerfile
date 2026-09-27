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
# No opcache: Alpine 3.24 ships php83-opcache and php84-opcache but has no
# php85-opcache yet, so `apk add php85-opcache` fails the whole layer. The
# Dockerfiles for 8.3/8.4 listed it, so dropping it here is a deliberate
# difference rather than an oversight.
#
# It costs nothing in dev: this image runs on a bind-mount over virtiofs, where
# every stat is slow, and opcache mainly saves re-parsing PHP — not I/O. For
# production, opcache belongs in a separate image anyway, where revalidate_freq
# and memory limits should differ from dev rather than be inherited.
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
