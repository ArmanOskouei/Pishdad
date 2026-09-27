# Alpine + prebuilt php84 packages: zero compilation, zero Debian mirror.
# (deb.debian.org is unreachable/slow from this network; dl-cdn.alpinelinux.org is fast.)
#
# 8.3 → 8.4 is step one of two. 8.3 is the oldest branch Laravel 13 accepts
# (php ^8.3) and its security support ends around Nov 2026, so staying put
# would mean a stream of fixes we can no longer receive. 8.5 is a separate step
# on purpose: if something breaks, doing it in two hops tells us which change
# did it.
FROM alpine:3.24

# php-fpm + every extension Laravel 13 / Sanctum / packages need, all prebuilt.
RUN apk add --no-cache \
    php84-fpm php84-opcache \
    php84-ctype php84-curl php84-dom php84-fileinfo php84-mbstring \
    php84-openssl php84-pdo php84-pdo_pgsql php84-pgsql php84-phar \
    php84-session php84-tokenizer php84-xml php84-simplexml php84-xmlwriter php84-xmlreader \
    php84-intl php84-bcmath php84-sockets php84-pcntl php84-zip \
    php84-pecl-redis php84-iconv \
    && ln -s /usr/bin/php84 /usr/bin/php \
    && ln -s /usr/sbin/php-fpm84 /usr/sbin/php-fpm

# Composer binary (image already pulled; no network cost at build)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Leverage layer cache: install deps first, then copy the rest on build
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts || true

COPY . .

# Local dev php overrides (php84 reads /etc/php84/conf.d/*.ini)
# The path must move with the package rename, otherwise the COPY lands in a
# directory nothing reads and the production php.ini is silently dropped.
COPY docker/php/php.ini /etc/php84/conf.d/99-local.ini
# FPM pool: listen on 9000 for the nginx service (last value wins over www.conf)
COPY docker/php/zz-docker.conf /etc/php84/php-fpm.d/zz-docker.conf

# Entrypoint: wait for db, ensure vendor, fix perms, run php-fpm
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
