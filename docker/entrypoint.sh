#!/usr/bin/env bash
# =============================================================================
# FlowSync Docker entrypoint
#
# Runs database initialisation (migrate → provision → seed-if-empty) only for
# the primary "app" service (RUN_INIT=true). Queue/reverb workers skip this.
# =============================================================================
set -euo pipefail

# ---------------------------------------------------------------------------
# Helper: wait for Postgres to accept connections
# ---------------------------------------------------------------------------
wait_for_db() {
    local host="${DB_HOST:-db}"
    local port="${DB_PORT:-5432}"
    local user="${DB_USERNAME:-flowsync}"
    local db="${DB_DATABASE:-flowsync_system}"
    local attempts=0
    local max_attempts=60

    echo "== waiting for PostgreSQL at ${host}:${port} =="
    until pg_isready -h "${host}" -p "${port}" -U "${user}" -d "${db}" > /dev/null 2>&1; do
        attempts=$((attempts + 1))
        if (( attempts >= max_attempts )); then
            echo "ERROR: PostgreSQL never became ready after ${max_attempts} attempts" >&2
            exit 1
        fi
        echo "  ... attempt ${attempts}/${max_attempts}"
        sleep 2
    done
    echo "== PostgreSQL is ready =="
}

# ---------------------------------------------------------------------------
# Helper: count users (returns -1 on any error so seed runs on first boot)
# ---------------------------------------------------------------------------
count_users() {
    local host="${DB_HOST:-db}"
    local port="${DB_PORT:-5432}"
    local user="${DB_USERNAME:-flowsync}"
    local db="${DB_DATABASE:-flowsync_system}"
    local pass="${DB_PASSWORD:-secret}"

    PGPASSWORD="${pass}" psql \
        -h "${host}" -p "${port}" \
        -U "${user}" -d "${db}" \
        -tAc 'SELECT COUNT(*) FROM users;' \
        2>/dev/null || echo "-1"
}

# ---------------------------------------------------------------------------
# Main init block (skipped for queue/reverb/worker containers)
# ---------------------------------------------------------------------------
if [[ "${RUN_INIT:-false}" == "true" ]]; then

    wait_for_db

    echo "== running system migrations =="
    php artisan migrate --force --no-interaction --database=system --path=database/migrations/system

    echo "== provisioning tenant permissions/roles =="
    php artisan tenants:provision 2>/dev/null \
        && echo "== tenants:provision done ==" \
        || echo "== tenants:provision skipped (no tenants yet) =="

    # Only seed if the database is empty (first boot)
    user_count="$(count_users)"
    echo "== user count: ${user_count} =="

    if [[ "${user_count}" == "0" || "${user_count}" == "-1" ]]; then
        echo "== seeding demo data =="
        php artisan db:seed --force --no-interaction
        echo "== seed complete =="
    else
        echo "== users already exist — skipping seed =="
    fi

    # Warm application caches
    echo "== warming caches =="
    php artisan config:cache --no-interaction 2>/dev/null || true
    php artisan route:cache  --no-interaction 2>/dev/null || true
    php artisan view:cache   --no-interaction 2>/dev/null || true

    echo "== init complete — starting server =="
fi

exec "$@"