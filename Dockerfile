# syntax=docker/dockerfile:1.7
#
# Production image for GLPI (https://glpi-project.org/).
#
# Build:
#   docker build -t glpi:11.0 .
#
# Run (needs a reachable MySQL/MariaDB server; see docker-compose.prod.yml
# for a full stack including the database):
#   docker run -d --name glpi -p 8080:8080 \
#     -v glpi_files:/var/www/glpi/files \
#     -v glpi_config:/var/www/glpi/config \
#     -v glpi_marketplace:/var/www/glpi/marketplace \
#     -v glpi_plugins:/var/www/glpi/plugins \
#     glpi:11.0

# ============================================================
# Stage 1: PHP dependencies (composer)
#
# Only composer.json/composer.lock (and the patches they apply) are needed
# here, so this layer is only invalidated when dependencies actually change
# — not on every application source change.
# ============================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Download packages first — cached as long as composer.json/lock don't
# change. --no-scripts/--no-autoloader because GLPI's own post-install
# scripts (and its "files" autoloader) reach into src/, which doesn't
# exist yet at this point.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs \
    --prefer-dist \
    --no-interaction

# Now bring in the application source (incl. tools/patches/*) and finish
# the install: dump the real autoloader and run GLPI's post-install
# scripts (vendor patches, hardware-inventory JSON generation).
COPY . .

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --classmap-authoritative \
    --ignore-platform-reqs \
    --prefer-dist \
    --no-interaction


# ============================================================
# Stage 2: front-end assets (webpack)
#
# Same caching logic as above: package.json/package-lock.json first, so
# `npm ci` is only re-run when JS dependencies change.
# ============================================================
FROM node:20-alpine AS assets

WORKDIR /app

# GLPI's own npm lifecycle scripts (see package.json) shell out to `php`
# (e.g. to stamp .package.hash) — the project's documented dev setup
# always has PHP alongside npm, so provide a minimal CLI for it here too.
RUN apk add --no-cache php83 && ln -s /usr/bin/php83 /usr/bin/php

COPY .npmrc package.json package-lock.json ./
COPY tools/ tools/
# Cypress is only for the e2e tests: skip its ~250 MB binary download.
ENV CYPRESS_INSTALL_BINARY=0
RUN npm ci

COPY .webpack.config.js .vue.webpack.config.js ./
COPY lib/ lib/
COPY js/ js/
COPY css/ css/
COPY public/ public/

RUN npx webpack --config .webpack.config.js \
 && npx webpack --config .vue.webpack.config.js


# ============================================================
# Stage 3: locales (.po -> .mo)
#
# The repo ships only the editable .po sources (locales/*.mo is
# build output, gitignored). GLPI refuses to install/run without the
# compiled catalogs, so they need to be generated at build time —
# `bin/console tools:locales:compile` just shells out to msgfmt per
# file, so it's reproduced directly here instead of pulling in a full
# --dev composer install (that command class only exists in
# autoload-dev) just to run it.
# ============================================================
FROM alpine:3.20 AS locales

RUN apk add --no-cache gettext

WORKDIR /app
COPY locales/ locales/
RUN for po in locales/*.po; do msgfmt -o "${po%.po}.mo" "$po"; done


# ============================================================
# Stage 4: runtime image
# ============================================================
FROM php:8.3-apache AS app

# ------------------------------------------------------------
# PHP extensions required by GLPI (see composer.json "require").
# Build deps are installed and purged in the same layer so they never
# end up in the final image.
# rclone: IT Backup copies each backup to an off-site destination (Windows
# file share / SMB...), see customizations/itbackup.
# ------------------------------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libicu-dev \
        libzip-dev \
        libbz2-dev \
        libldap-dev \
        libonig-dev \
        default-mysql-client \
        rclone; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-configure ldap; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mysqli \
        pdo_mysql \
        zip \
        bz2 \
        ldap \
        opcache; \
    apt-get purge -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libicu-dev \
        libzip-dev \
        libbz2-dev \
        libldap-dev \
        libonig-dev; \
    rm -rf /var/lib/apt/lists/*

# ------------------------------------------------------------
# Apache: rewrite/headers/expires modules, document root, and
# switch to an unprivileged port so the server can run as www-data
# instead of root (the stock image already owns
# /var/log/apache2, /var/lock/apache2 and /var/run/apache2 by www-data).
# ------------------------------------------------------------
ENV APACHE_DOCUMENT_ROOT=/var/www/glpi/public

RUN set -eux; \
    a2enmod rewrite headers expires; \
    sed -ri \
        -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    echo "ServerName localhost" >> /etc/apache2/conf-available/docker-php.conf

COPY php/glpi-vhost.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

# ------------------------------------------------------------
# PHP configuration
# ------------------------------------------------------------
COPY php/custom.ini /usr/local/etc/php/conf.d/zz-glpi-custom.ini

# ------------------------------------------------------------
# Application code
#
# Dependencies/assets are copied first (stable), full source last
# (changes most often) to keep the cache useful during iteration.
# ------------------------------------------------------------
WORKDIR /var/www/glpi

COPY --chown=www-data:www-data --from=vendor /app ./
COPY --chown=www-data:www-data --from=assets /app/public/lib ./public/lib
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
COPY --chown=www-data:www-data --from=assets /app/css/lib ./css/lib
COPY --chown=www-data:www-data --from=locales /app/locales ./locales

# GLPI needs to write into these directories at runtime; mount them as
# volumes in production so data survives container recreation.
RUN chown -R www-data:www-data files config marketplace plugins

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=5 \
    CMD curl -fsS http://localhost:8080/ -o /dev/null || exit 1

CMD ["apache2-foreground"]
