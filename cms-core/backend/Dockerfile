# Alpine + prebuilt php83 packages: zero compilation, zero Debian mirror.
# (deb.debian.org is unreachable/slow from this network; dl-cdn.alpinelinux.org is fast.)
FROM alpine:3.24

# php-fpm + every extension Laravel 11 / Sanctum / packages need, all prebuilt.
RUN apk add --no-cache \
    php83-fpm php83-opcache \
    php83-ctype php83-curl php83-dom php83-fileinfo php83-mbstring \
    php83-openssl php83-pdo php83-pdo_pgsql php83-pgsql php83-phar \
    php83-session php83-tokenizer php83-xml php83-simplexml php83-xmlwriter php83-xmlreader \
    php83-intl php83-bcmath php83-sockets php83-pcntl php83-zip \
    php83-pecl-redis php83-iconv \
    && ln -s /usr/bin/php83 /usr/bin/php \
    && ln -s /usr/sbin/php-fpm83 /usr/sbin/php-fpm

# Composer binary (image already pulled; no network cost at build)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Leverage layer cache: install deps first, then copy the rest on build
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts || true

COPY . .

# Local dev php overrides (php83 reads /etc/php83/conf.d/*.ini)
COPY docker/php/php.ini /etc/php83/conf.d/99-local.ini
# FPM pool: listen on 9000 for the nginx service (last value wins over www.conf)
COPY docker/php/zz-docker.conf /etc/php83/php-fpm.d/zz-docker.conf

# Entrypoint: wait for db, ensure vendor, fix perms, run php-fpm
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
