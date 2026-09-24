# syntax=docker/dockerfile:1
# =============================================================================
# FlowSync — Multi-stage Docker build
#
# Stage 1 (frontend):  Node 20-alpine + Vite → builds React SPA into public/build/
# Stage 2 (app):       PHP 8.3-cli-bookworm (Debian) + Composer production deps
#                      + entrypoint: migrate → provision → seed-if-empty → serve
#
# Debian base avoids the Alpine/LLVM build-time blowup caused by icu-dev.
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1 — Frontend (React + Vite)
# ---------------------------------------------------------------------------
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --prefer-offline

COPY . .

# VITE_* vars are baked into the JS bundle — must be browser-reachable values.
ARG VITE_APP_NAME="FlowSync"
ARG VITE_REVERB_APP_KEY=3epdhhaqs959bljmaety
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http

ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — PHP runtime (Debian Bookworm — fast apt deps, no LLVM bloat)
# ---------------------------------------------------------------------------
FROM php:8.3-cli-bookworm AS app

# Install system dependencies via apt (much faster than Alpine/LLVM)
RUN apt-get update && apt-get install -y --no-install-recommends \
        bash \
        curl \
        libpq-dev \
        libzip-dev \
        libonig-dev \
        libicu-dev \
        libpng-dev \
        unzip \
        postgresql-client \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl \
        posix \
        sockets \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer (official installer)
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# PHP deps layer-cached separately from app source
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

# Full application source
COPY . .

# Built React SPA from Stage 1
COPY --from=frontend /app/public/build /app/public/build

# Regenerate autoload + service-provider discovery
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# OPcache configuration for CLI server
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.max_accelerated_files=10000"; \
        echo "opcache.revalidate_freq=0"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Storage directories owned by www-data
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]