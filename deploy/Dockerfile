# One image for web, queue workers, scheduler and migrations (billing/docs/devops/deployment-options.md).
#   docker build -f deploy/Dockerfile --target prod -t billing:local .     (from the repository root)
# The context is the repository root, narrowed by deploy/Dockerfile.dockerignore to billing/ and the runtime
# files under deploy/. Targets: prod (default, last stage), dev and release (the tree for non-Docker hosts).
#
# Every base image is <name>:<tag>@sha256:<digest> and at least 7 days old (ADR 0007); the dependency check
# (`composer deps:check`) scans the FROM and COPY --from lines of this file.

# ------------------------------------------------------------------ php: FrankenPHP with the app's extensions
# FrankenPHP 1.13.2 (Caddy 2.11.7, PHP 8.4.27 ZTS) on Debian trixie, multi-arch index pushed 2026-10-21.
# Adopted inside the 7-day cooldown as a security exception (dependency-policy.md): PHP 8.4.27 fixes
# CVE-2026-109618 and CVE-2026-109619 (unauthenticated RCE in every web SAPI); 8.4.26 and older are vulnerable.
# Builder for every other stage; it keeps the compiler toolchain of the official PHP images (about 840 MB).
FROM dunglas/frankenphp:1.13.2-php8.4-trixie@sha256:0de5a32944f72c13b45d183e8e6c53546b8172cec21cc8599e24b12e0661506d AS php

# Core PHP extensions only (no PECL downloads); install-php-extensions ships with the image and removes its
# build dependencies afterwards. OPcache is already built in. Port 8080 needs no capability, and a file
# capability would make exec fail under cap_drop: ALL, so it is removed.
RUN install-php-extensions bcmath gd intl pcntl pdo_pgsql pgsql zip \
    && setcap -r /usr/local/bin/frankenphp

WORKDIR /app

# ------------------------------------------------------------------ php-libs: shared libraries PHP links against
FROM php AS php-libs

RUN set -eux; \
    mkdir -p /out; \
    for file in /usr/local/bin/frankenphp /usr/local/bin/php "$(php -r 'echo ini_get("extension_dir");')"/*.so; do \
        ldd "$file"; \
    done | awk '$2 == "=>" && $3 ~ /^\// { print $3 }' | sort -u | xargs -r cp -L -t /out

# ------------------------------------------------------------------ runtime: PHP without the toolchain
# Debian trixie slim, the snapshot the PHP base was built from (same glibc 2.41-12+deb13u4), pushed 2026-10-06.
FROM debian:trixie-20261005-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f AS runtime

# Libraries the slim image already has stay Debian's; only the missing ones are added to /usr/local/lib.
COPY --from=php-libs /out /tmp/php-libs
RUN set -eux; \
    for lib in /tmp/php-libs/*; do \
        name="$(basename "$lib")"; \
        if ! ldconfig -p | awk '{ print $1 }' | grep -qxF "$name"; then mv "$lib" /usr/local/lib/; fi; \
    done; \
    rm -rf /tmp/php-libs; \
    ldconfig; \
    groupadd --gid 10001 app; \
    useradd --uid 10001 --gid app --no-create-home --home-dir /app --shell /usr/sbin/nologin app

COPY --from=php /usr/local/bin/frankenphp /usr/local/bin/php /usr/local/bin/
COPY --from=php /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=php /usr/local/etc/php /usr/local/etc/php
# The slim image has no CA store and no /usr/lib/ssl, so OpenSSL's default paths are empty: PHP stream TLS (SMTP
# STARTTLS, https:// streams) would not verify. Point OpenSSL at the bundle (app.ini sets openssl.cafile too).
COPY --from=php /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt

ENV PHP_INI_DIR=/usr/local/etc/php \
    SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

WORKDIR /app

# ------------------------------------------------------------------ vendor: production Composer dependencies
FROM php AS vendor

# Composer 2.10.3 (the CI version), multi-arch index pushed 2026-09-30.
COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

ENV COMPOSER_NO_INTERACTION=1 COMPOSER_NO_AUDIT=1

COPY billing/composer.json billing/composer.lock ./
RUN --mount=type=cache,target=/root/.cache/composer \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY billing/ .
# Backstop for Dockerfile.dockerignore: local state (an .env, logs, sessions, dev-tool recordings) must never reach
# the image, where Docker would also seed the storage volume from it.
RUN leaked="$(find . -path ./vendor -prune -o \( -name '.env' -o -name '.env.*' \) ! -name .env.example -print; \
        find storage -type f ! -name .gitignore; \
        find storage -mindepth 1 -maxdepth 1 ! -name app ! -name framework ! -name logs)"; \
    if [ -n "$leaked" ]; then echo "Local files in the build context:" >&2; echo "$leaked" >&2; exit 1; fi
# Runs the post-autoload-dump scripts: package discovery writes bootstrap/cache/{packages,services}.php.
RUN composer dump-autoload --no-dev --optimize

# ------------------------------------------------------------------ wayfinder: TypeScript route helpers for the build
FROM vendor AS wayfinder

# A throwaway key and an in-memory database: generating routes boots the app but stores nothing.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_ENV=production DB_CONNECTION=sqlite DB_DATABASE=:memory: \
    php artisan wayfinder:generate --with-form

# ------------------------------------------------------------------ assets: Vite build (only public/build leaves)
# Node 24.21.0 (billing/.nvmrc) on Debian trixie slim, multi-arch index pushed 2026-10-06.
FROM node:24.21.0-trixie-slim@sha256:173f125896c3b47ddf056734c7ea789d04595a6a08769a8f78e0df642781fb66 AS assets

WORKDIR /app

COPY billing/package.json billing/package-lock.json billing/.npmrc ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

COPY billing/vite.config.ts billing/tsconfig.json billing/components.json ./
COPY billing/resources resources
COPY billing/public public
COPY --from=wayfinder /app/resources/js/actions resources/js/actions
COPY --from=wayfinder /app/resources/js/routes resources/js/routes
COPY --from=wayfinder /app/resources/js/wayfinder resources/js/wayfinder
# Tailwind scans the framework's pagination views (resources/css/app.css @source).
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views vendor/laravel/framework/src/Illuminate/Pagination/resources/views

# The routes were generated above; the Vite plugin must not call PHP here.
RUN WAYFINDER_COMMAND=true npm run build

# ------------------------------------------------------------------ dev: PHP, Composer and Node; source bind-mounted
FROM php AS dev

COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
COPY --from=node:24.21.0-trixie-slim@sha256:173f125896c3b47ddf056734c7ea789d04595a6a08769a8f78e0df642781fb66 /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24.21.0-trixie-slim@sha256:173f125896c3b47ddf056734c7ea789d04595a6a08769a8f78e0df642781fb66 /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && mkdir -p /data/caddy /config/caddy && chmod -R a+rwX /data /config

COPY deploy/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

ENV SERVER_NAME=:8080

# The source tree is bind-mounted at /app; compose.dev.yaml runs it as the host user.
ENTRYPOINT []
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

# ------------------------------------------------------------------ smoke-seed: the app with dev dependencies
# Used only by deploy/bin/smoke to run the demo seeders (they need Faker and the model factories, which the prod image
# never contains). Never deployed or published.
FROM php AS smoke-seed

COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

ENV COMPOSER_NO_INTERACTION=1 COMPOSER_NO_AUDIT=1

# The dev dependency mohar/simulators is a path repository (symlinked), so it must exist before the install.
COPY billing/tools/simulators tools/simulators
COPY billing/composer.json billing/composer.lock ./
RUN --mount=type=cache,target=/root/.cache/composer \
    composer install --no-scripts --no-autoloader --prefer-dist --no-progress

COPY billing/ .
RUN composer dump-autoload --optimize

# ------------------------------------------------------------------ release: the app tree for hosts without Docker
# The source with production vendor/ and the built public/build, nothing else: the release tarball for the
# non-Docker install (install.md) and cPanel cron mode (cpanel.md). Export it with
#   docker build -f deploy/Dockerfile --target release --output type=tar,dest=release.tar .
FROM scratch AS release
COPY --from=vendor /app /billing
COPY --from=assets /app/public/build /billing/public/build

# ------------------------------------------------------------------ prod: the runtime image
FROM runtime AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY deploy/php/opcache.ini "$PHP_INI_DIR/conf.d/zz-opcache.ini"
COPY deploy/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=0755 deploy/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=0755 deploy/healthcheck.php /usr/local/bin/app-healthcheck

# Code is owned by root and read-only to the app; only storage/ (a volume) is writable. bootstrap/cache, /tmp
# and Caddy's /data and /config are tmpfs mounts in production (read-only root filesystem).
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/logs /data/caddy /config/caddy \
    && chown -R app:app storage bootstrap/cache /data /config \
    # The slimmed runtime must still load every extension the app needs, and OpenSSL must find the CA bundle.
    && php -r 'foreach (["bcmath","ctype","curl","dom","fileinfo","gd","intl","mbstring","openssl","pcntl","pdo_pgsql", \
        "pgsql","sodium","zip","Zend OPcache"] as $e) { if (! extension_loaded($e)) { fwrite(STDERR, "missing $e\n"); exit(1); } } \
        if (! is_readable((string) ini_get("openssl.cafile"))) { fwrite(STDERR, "no CA bundle\n"); exit(1); }' \
    # Never ship a PHP build with CVE-2026-109618 / CVE-2026-109619 (same floor as the app's system check).
    && php -r 'if (version_compare(PHP_VERSION, "8.4.27", "<")) { fwrite(STDERR, "PHP ".PHP_VERSION." is vulnerable, need 8.4.27+\n"); exit(1); }' \
    && php -v \
    && frankenphp version

# Compiled views stay per container (bootstrap/cache is a tmpfs): two versions running side by side during a
# blue-green deploy must never share them through the storage volume.
ENV APP_ENV=production \
    APP_DEBUG=false \
    VIEW_COMPILED_PATH=/app/bootstrap/cache/views \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    INERTIA_SSR_ENABLED=false

USER app
EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=3 CMD ["app-healthcheck"]

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
