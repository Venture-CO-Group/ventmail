# syntax=docker/dockerfile:1.7
#
# ventMail production image. One image, four roles chosen by the command:
#   app        php-fpm on the unix socket /run/php/fpm.sock
#   worker     Horizon (the send queue: transactional > journey > campaign)
#   scheduler  schedule:work
#   web        Caddy on :8080: static public/ + FastCGI over that socket
#
# Frontend assets (public/build) are built by the CI `frontend` job from the
# same commit (vue-tsc + vite, with the private @venture/* siblings checked out)
# and handed to this build as an artifact. The build refuses to run without them.
#
# Built and pushed only by .github/workflows/release.yml. Never on the server.
# The same pattern as ventSSO; PHP 8.3 is the ventMail spec's locked version.

ARG PHP_IMAGE=php:8.3.33-fpm-alpine3.24
ARG COMPOSER_IMAGE=composer:2.9.8
ARG CADDY_IMAGE=caddy:2.11.7-alpine

# --- composer dependencies -----------------------------------------------------
FROM ${COMPOSER_IMAGE} AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# pcntl/posix/sockets are absent in the composer image but present at runtime.
RUN composer install --no-dev --optimize-autoloader --no-scripts --prefer-dist \
        --no-progress --no-interaction \
        --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --ignore-platform-req=ext-sockets
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM ${CADDY_IMAGE} AS caddy

# --- runtime ------------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime
WORKDIR /app

ARG PHP_EXTENSION_INSTALLER=2.12.0
ADD --chmod=0755 --checksum=sha256:3f49c71fa66c79b8b2b96bc0ce92885dafd7946f3b5a46f545b390d6f1617e2c https://github.com/mlocati/docker-php-extension-installer/releases/download/${PHP_EXTENSION_INSTALLER}/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pcntl opcache redis intl zip bcmath sockets \
    && apk add --no-cache fcgi \
    && addgroup -g 10001 -S ventmail \
    && adduser -u 10001 -S -D -H -G ventmail ventmail \
    && rm -f /usr/local/etc/php-fpm.d/zz-docker.conf.dist

COPY --from=caddy /usr/bin/caddy /usr/local/bin/caddy
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-ventmail.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/caddy/Caddyfile.web /etc/caddy/Caddyfile
COPY docker/caddy/snippets /etc/caddy/snippets
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=0755 docker/healthcheck.sh /usr/local/bin/healthcheck

COPY --chown=10001:10001 . .
COPY --from=vendor --chown=10001:10001 /app/vendor ./vendor

RUN test -f public/build/manifest.json \
        || { echo 'public/build is missing: build the frontend first (CI frontend job).' >&2; exit 1; } \
    && rm -f public/hot .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
    && chown -R 10001:10001 storage bootstrap/cache \
    && install -d -o 10001 -g 10001 -m 0750 /run/php

ARG VERSION=dev
ARG REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/Venture-CO-Group/ventmail" \
      org.opencontainers.image.title="ventMail" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}"
ENV VENTMAIL_VERSION=${VERSION} \
    XDG_DATA_HOME=/tmp/caddy-data \
    XDG_CONFIG_HOME=/tmp/caddy-config

USER 10001:10001
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 CMD ["healthcheck"]

ENTRYPOINT ["entrypoint"]
CMD ["app"]
