# syntax=docker/dockerfile:1

FROM node:22-trixie-slim AS node

FROM --platform=$BUILDPLATFORM node:22-trixie-slim AS frontend
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM node AS ml
WORKDIR /app/ml-processor
COPY services/ml-processor/package.json services/ml-processor/package-lock.json ./
RUN ONNXRUNTIME_NODE_INSTALL=skip npm ci --omit=dev --no-audit --no-fund \
 && arch="$(node -p process.arch)" \
 && find node_modules/onnxruntime-node/bin -mindepth 3 -maxdepth 3 -type d ! -path "*/linux/$arch" -exec rm -rf {} + \
 && find node_modules/onnxruntime-node/bin -mindepth 2 -maxdepth 2 -type d ! -name linux -exec rm -rf {} +
COPY services/ml-processor/ ./
RUN npm run fetch-model

FROM php:8.3-fpm-trixie

COPY --from=node /usr/local/bin/node /usr/local/bin/node

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx sqlite3 tini unzip \
      libicu76 libzip5 libpng16-16t64 libjpeg62-turbo libwebp7 libstdc++6; \
    dev="libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev"; \
    apt-get install -y --no-install-recommends $dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" intl zip gd opcache; \
    apt-get purge -y --auto-remove $dev; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qx intl; php -m | grep -qx gd; php -m | grep -qx zip; \
    node --version

COPY backend/tools/sqlite-vec.sh /tmp/sqlite-vec.sh
RUN bash /tmp/sqlite-vec.sh /usr/local/lib/sqlite-vec && rm /tmp/sqlite-vec.sh

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/memex.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-memex.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/headers.conf /etc/nginx/memex-headers.conf

WORKDIR /app/backend
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APP_BASE_URL=http://localhost:8080 \
    MEMEX_DATA_DIR=/data \
    COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY backend/ ./
ARG RELEASE=unreleased
RUN rm -rf var .env.local .env.*.local \
 && composer dump-autoload --no-dev --classmap-authoritative \
 && echo "$RELEASE" > RELEASE \
 && php bin/console cache:warmup \
 && rm -rf var/log \
 && ln -s /data/log var/log \
 && ln -s /data/secret.env .env.prod.local \
 && chown -R www-data:www-data var

COPY --from=frontend /src/frontend/dist /app/web
COPY --from=ml /app/ml-processor /app/ml-processor
COPY docker/start.sh docker/schedule.sh docker/backup.sh /app/docker/
COPY docker/php-as-www-data /usr/local/sbin/php

RUN mkdir -p /data /run/nginx /var/lib/nginx \
 && chown -R www-data:www-data /data /run/nginx /var/lib/nginx /var/log/nginx /app/ml-processor/config

VOLUME /data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD curl -fsS http://127.0.0.1:8080/api/health > /dev/null || exit 1
ENTRYPOINT ["tini", "--", "/app/docker/start.sh"]
