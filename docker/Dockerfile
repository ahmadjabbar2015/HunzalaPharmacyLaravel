# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 - front-end assets.
#
# Built in its own stage so node and its ~200MB of modules never reach the
# runtime image. Only the compiled public/build/ directory is copied forward.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

# package.json alone, so a source-only change does not re-run the install.
COPY package.json package-lock.json* ./

# npm ci is preferred - it is reproducible - but it hard-fails without a
# lockfile, and the scaffold does not commit one. Fall back rather than making
# the build depend on a file that may not be there.
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


# ---------------------------------------------------------------------------
# Stage 2 - PHP dependencies.
#
# Composer runs without the application code so the dependency layer caches on
# composer.lock rather than on every edit to app/.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# --no-scripts because artisan is not present yet; package discovery runs in
# the runtime stage once the full tree is in place.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction


# ---------------------------------------------------------------------------
# Stage 3 - runtime.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# mysql-client is here for the backup service, which shells out to mysqldump.
RUN apk add --no-cache \
        mysql-client \
        fcgi \
        icu-libs \
        libzip \
        freetype \
        libjpeg-turbo \
        libpng \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# bcmath          - brick/math's fast backend for decimal money arithmetic.
#                   Without it brick/math falls back to a pure-PHP BCD
#                   implementation, which is correct but materially slower on
#                   a long sale.
# gd              - dompdf image rendering for receipts and reports.
# pcntl           - queue workers need signal handling to shut down cleanly
#                   instead of being killed mid-job.
# opcache         - see php/opcache.ini; the single biggest production win.
# redis           - CACHE_STORE and QUEUE_CONNECTION are both redis, and
#                   migrate --isolated takes its lock through the cache
#                   store, so without phpredis the container cannot even
#                   boot. From PECL: there is no alpine package for it.

WORKDIR /var/www/html

COPY docker/php/php.ini      /usr/local/etc/php/conf.d/99-pharmacy.ini
COPY docker/php/opcache.ini  /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pharmacy.conf

COPY --from=vendor  /app/vendor       ./vendor
COPY --from=assets  /app/public/build ./public/build
COPY . .

# Now that artisan and the full tree exist, finish what --no-scripts skipped.
# The runtime base is php-fpm-alpine, which ships no composer, so borrow the
# binary from the vendor stage for this one step and drop it again - composer
# has no business being present in a production image.
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -f /usr/local/bin/composer

# storage/ and bootstrap/cache must be writable by the FPM worker user.
RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Reports FPM unhealthy if the pool stops answering, so compose restarts it
# rather than leaving nginx serving 502s.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD REDIRECT_STATUS=true SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping \
        REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]


# ---------------------------------------------------------------------------
# Stage 4 - nginx.
#
# nginx serves public/ from its own image rather than from a volume shared with
# the app. A shared named volume is populated only the first time it is created,
# so after a rebuild it would keep serving the OLD compiled assets against the
# NEW application code - a stale-cache bug that appears days later and looks
# like anything but a volume problem.
#
# Built from this same Dockerfile so it reuses the assets stage's cache instead
# of compiling the front end a second time.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

RUN apk add --no-cache openssl

# Only public/ - nginx must never be able to read app/, .env, or storage/.
COPY public                     /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build

COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY docker/nginx/01-ensure-certs.sh    /docker-entrypoint.d/01-ensure-certs.sh
RUN chmod +x /docker-entrypoint.d/01-ensure-certs.sh
