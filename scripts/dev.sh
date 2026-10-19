#!/usr/bin/env bash
# =============================================================================
# FlowSync local development runner
#
#   ./scripts/dev.sh docker [up|down|restart|logs|shell|rebuild|reset|status]
#   ./scripts/dev.sh local  [setup|start|stop|status]
#   ./scripts/dev.sh help
#
# docker  Everything in containers (PostgreSQL, app, queue, Reverb, Vite).
#         The project folder is bind-mounted: edits show up in the browser
#         immediately (React via Vite HMR, PHP/Blade on refresh).
# local   No Docker. Uses your own PHP 8.2+, Composer, Node 20+, PostgreSQL.
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

C_RESET=$'\033[0m'; C_BLUE=$'\033[34m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'
info() { echo "${C_BLUE}==>${C_RESET} $*"; }
ok()   { echo "${C_GREEN} ✓${C_RESET} $*"; }
warn() { echo "${C_YELLOW} !${C_RESET} $*"; }
die()  { echo "${C_RED} ✗ $*${C_RESET}" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

rand() { # rand <chars>
    LC_ALL=C tr -dc 'a-z0-9' </dev/urandom | head -c "$1"
}

# Set KEY=value in an env file (adds the key when missing; keeps other lines).
set_env() { # set_env <file> <KEY> <value>
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then
        # '|' delimiter; escape &, \ and | in the value
        local esc; esc="$(printf '%s' "$value" | sed -e 's/[\&|]/\\&/g')"
        sed -i.bak -E "s|^${key}=.*|${key}=${esc}|" "$file" && rm -f "$file.bak"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}
# Set only when the current value is empty/missing.
default_env() { # default_env <file> <KEY> <value>
    local file="$1" key="$2" value="$3" cur
    cur="$(grep -E "^${key}=" "$file" | head -1 | cut -d= -f2- | tr -d '"' || true)"
    [[ -n "$cur" ]] || set_env "$file" "$key" "$value"
}

# ----------------------------------------------------------------------------
# DOCKER
# ----------------------------------------------------------------------------
dc() { docker compose "$@"; }

docker_prepare() {
    have docker || die "Docker is not installed."
    docker compose version >/dev/null 2>&1 || die "Docker Compose v2 ('docker compose') is required."

    # Run containers as your user so files created in the bind mount stay yours.
    export DOCKER_UID="${DOCKER_UID:-$(id -u)}"
    export DOCKER_GID="${DOCKER_GID:-$(id -g)}"

    if [[ ! -f .env.docker ]]; then
        [[ -f .env.docker.example ]] || die ".env.docker.example is missing."
        cp .env.docker.example .env.docker
        info "Created .env.docker from the example"
    fi
    if [[ -z "$(grep -E '^APP_KEY=' .env.docker | cut -d= -f2-)" ]]; then
        set_env .env.docker APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
        ok "Generated APP_KEY"
    fi
    local key; key="$(grep -E '^REVERB_APP_KEY=' .env.docker | cut -d= -f2-)"
    if [[ -z "$key" ]]; then
        key="$(rand 20)"
        set_env .env.docker REVERB_APP_ID "$(rand 6 | tr 'a-z' '0-9' | head -c 6)"
        set_env .env.docker REVERB_APP_KEY "$key"
        set_env .env.docker REVERB_APP_SECRET "$(rand 20)"
        ok "Generated Reverb credentials"
    fi
    # The browser key must match the server key.
    set_env .env.docker VITE_REVERB_APP_KEY "$key"

    # Folders the bind mount needs to be writable by your user.
    mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
    [[ -w storage && -w bootstrap/cache ]] || warn "storage/ or bootstrap/cache is not writable by you — run: sudo chown -R $USER storage bootstrap/cache"
}

docker_wait() {
    info "Waiting for the app to become healthy (first start migrates + seeds, ~1-3 min)"
    local i
    for i in $(seq 1 90); do
        if curl -sf http://localhost:8000/up >/dev/null 2>&1; then
            ok "App is up"; return 0
        fi
        sleep 3
    done
    warn "Still starting. Follow progress with: ./scripts/dev.sh docker logs"
}

docker_summary() {
    cat <<EOF

${C_GREEN}FlowSync is running in Docker${C_RESET}
  App (SPA)     http://localhost:8000/app
  Public site   http://localhost:8000/
  Vite (HMR)    http://localhost:5173
  Reverb (ws)   ws://localhost:8080
  PostgreSQL    localhost:${FORWARD_DB_PORT:-5434}  (flowsync / secret)

  Demo logins (password: password)
    superadmin@flowsync.test   Super Admin
    admin@flowsync.test        Acme admin
    owner@globex.test          Globex admin

  Edit files and refresh — React updates live, PHP on reload.
  Logs: ./scripts/dev.sh docker logs     Stop: ./scripts/dev.sh docker down
EOF
}

cmd_docker() {
    local action="${1:-up}"; shift || true
    docker_prepare
    case "$action" in
        up|start)
            info "Starting the stack (builds the image on first run)"
            dc up -d --build "$@"
            docker_wait
            docker_summary
            ;;
        down|stop) dc down "$@"; ok "Stopped (data volumes kept)";;
        restart)   dc restart "$@"; ok "Restarted";;
        logs)      dc logs -f --tail=100 "${@:-app}";;
        status)    dc ps;;
        shell)     dc exec app bash;;
        artisan)   dc exec app php artisan "$@";;
        rebuild)
            info "Rebuilding the image and refreshing vendor/node_modules volumes"
            dc down
            docker volume ls -q | grep -E '_flowsync_(vendor|node_modules)$' | xargs -r docker volume rm >/dev/null 2>&1 || true
            dc build app
            dc up -d
            docker_wait
            docker_summary
            ;;
        reset)
            warn "This deletes ALL local data (databases, uploads) and re-seeds demo data."
            read -r -p "Type 'yes' to continue: " a
            [[ "$a" == "yes" ]] || die "Aborted."
            dc down -v
            dc up -d --build
            docker_wait
            docker_summary
            ;;
        *) die "Unknown docker action '$action'. Try: up down restart logs status shell artisan rebuild reset";;
    esac
}

# ----------------------------------------------------------------------------
# LOCAL (no Docker)
# ----------------------------------------------------------------------------
LOCAL_PIDS_FILE="$ROOT/storage/.dev-local.pids"

local_check_tools() {
    local missing=0
    for t in php composer node npm; do
        have "$t" || { warn "Missing: $t"; missing=1; }
    done
    (( missing == 0 )) || die "Install the missing tools first (PHP 8.2+ with pdo_pgsql, Composer, Node 20+)."
    php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' || die "PHP 8.2+ required (found $(php -r 'echo PHP_VERSION;'))."
    php -m | grep -qi '^pdo_pgsql$' || die "PHP extension pdo_pgsql is missing (e.g. apt install php-pgsql)."
    local nodev; nodev="$(node -p 'process.versions.node.split(".")[0]')"
    (( nodev >= 20 )) || warn "Node 20+ recommended (found $(node -v))."
}

local_db_ready() {
    have pg_isready || { warn "pg_isready not found — skipping PostgreSQL check"; return 0; }
    pg_isready -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" >/dev/null 2>&1
}

local_setup_db() {
    local host="${DB_HOST:-127.0.0.1}" port="${DB_PORT:-5432}"
    local_db_ready || die "PostgreSQL is not reachable at ${host}:${port}. Start it (e.g. 'sudo systemctl start postgresql') and re-run."

    export PGPASSWORD="secret"
    if psql -h "$host" -p "$port" -U flowsync -d flowsync_system -c 'select 1' >/dev/null 2>&1; then
        ok "Database flowsync_system is reachable as user flowsync"
        return 0
    fi

    warn "Role/database flowsync not found — trying to create them (needs a PostgreSQL superuser)"
    local sql="DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='flowsync') THEN CREATE ROLE flowsync LOGIN CREATEDB PASSWORD 'secret'; END IF; END \$\$;"
    if have sudo && sudo -n -u postgres psql -c "select 1" >/dev/null 2>&1; then
        sudo -u postgres psql -c "$sql" >/dev/null
        sudo -u postgres psql -tc "select 1 from pg_database where datname='flowsync_system'" | grep -q 1 \
            || sudo -u postgres createdb -O flowsync flowsync_system
        ok "Created role and database"
    else
        cat <<EOF
Run this once as a PostgreSQL superuser, then re-run setup:

  sudo -u postgres psql -c "CREATE ROLE flowsync LOGIN CREATEDB PASSWORD 'secret';"
  sudo -u postgres createdb -O flowsync flowsync_system
EOF
        exit 1
    fi
}

local_write_env() {
    if [[ ! -f .env ]]; then
        cp .env.example .env
        info "Created .env from .env.example"
    fi
    # The central DB is PostgreSQL; tenant DBs are PostgreSQL too (one per tenant).
    set_env .env APP_ENV local
    set_env .env APP_DEBUG true
    set_env .env APP_URL http://localhost:8000
    set_env .env DB_CONNECTION system
    set_env .env DB_HOST 127.0.0.1
    set_env .env DB_PORT 5432
    set_env .env DB_USERNAME flowsync
    set_env .env DB_PASSWORD secret
    set_env .env DB_SYSTEM_DATABASE flowsync_system
    set_env .env TENANCY_DRIVER isolated
    set_env .env TENANT_DB_DRIVER pgsql
    set_env .env TENANT_DB_PREFIX flowsync_tenant_
    # One shared owner role for tenant DBs (the flowsync role has CREATEDB, not CREATEROLE).
    set_env .env TENANT_DB_PG_ROLE flowsync
    set_env .env SESSION_CONNECTION system
    set_env .env DB_CACHE_CONNECTION system
    set_env .env DB_QUEUE_CONNECTION system
    set_env .env QUEUE_CONNECTION database
    set_env .env BROADCAST_CONNECTION reverb

    local key; key="$(grep -E '^REVERB_APP_KEY=' .env | cut -d= -f2- | tr -d '"')"
    if [[ -z "$key" ]]; then
        key="$(rand 20)"
        set_env .env REVERB_APP_ID "$(rand 6 | tr 'a-z' '0-9' | head -c 6)"
        set_env .env REVERB_APP_KEY "$key"
        set_env .env REVERB_APP_SECRET "$(rand 20)"
        ok "Generated Reverb credentials"
    fi
    set_env .env REVERB_HOST localhost
    set_env .env REVERB_PORT 8080
    set_env .env REVERB_SCHEME http
}

local_setup() {
    local_check_tools
    local_write_env
    info "Installing PHP dependencies"
    composer install --no-interaction --prefer-dist
    if [[ -z "$(grep -E '^APP_KEY=' .env | cut -d= -f2-)" ]]; then
        php artisan key:generate --force
    fi

    info "Installing JS dependencies"
    npm install --no-audit --no-fund

    local_setup_db

    info "Migrating the system database"
    php artisan migrate --force --database=system --path=database/migrations/system

    info "Provisioning tenants / seeding demo data (first run only seeds)"
    local users
    users="$(PGPASSWORD=secret psql -h 127.0.0.1 -U flowsync -d flowsync_system -tAc 'select count(*) from users' 2>/dev/null || echo 0)"
    if [[ "${users:-0}" == "0" ]]; then
        php artisan db:seed --force
    fi
    php artisan tenants:provision || warn "tenants:provision reported problems — see output above"
    php artisan storage:link >/dev/null 2>&1 || true
    php artisan optimize:clear >/dev/null
    ok "Setup complete"
}

local_start() {
    [[ -f .env && -d vendor && -d node_modules ]] || { info "First run — setting up"; local_setup; }
    local_check_tools
    local_db_ready || die "PostgreSQL is not running."
    [[ ! -f "$LOCAL_PIDS_FILE" ]] || { warn "Already running (or stale). Run './scripts/dev.sh local stop' first."; }

    cat <<EOF

${C_GREEN}Starting FlowSync (no Docker)${C_RESET}
  App (SPA)     http://localhost:8000/app
  Public site   http://localhost:8000/
  Vite (HMR)    http://localhost:5173
  Reverb (ws)   ws://localhost:8080
  Logins (password: password): superadmin@flowsync.test, admin@flowsync.test, owner@globex.test
  Ctrl+C stops everything.

EOF
    echo "$$" >"$LOCAL_PIDS_FILE"
    trap 'rm -f "$LOCAL_PIDS_FILE"' EXIT

    # serve + queue + reverb + vite, one terminal, prefixed output.
    npx --no-install concurrently -k -n server,queue,reverb,vite -c "blue,magenta,red,green" \
        "php artisan serve --host=127.0.0.1 --port=8000" \
        "php artisan queue:listen database --tries=3 --sleep=1" \
        "php artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction" \
        "npm run dev"
}

local_stop() {
    if [[ -f "$LOCAL_PIDS_FILE" ]]; then
        kill "$(cat "$LOCAL_PIDS_FILE")" 2>/dev/null || true
        rm -f "$LOCAL_PIDS_FILE"
        ok "Stopped"
    else
        warn "Nothing recorded as running (use Ctrl+C in the terminal that started it)."
    fi
}

local_status() {
    for p in "8000:app" "5173:vite" "8080:reverb"; do
        if (echo >"/dev/tcp/127.0.0.1/${p%%:*}") 2>/dev/null; then ok "${p##*:} listening on :${p%%:*}"; else warn "${p##*:} not listening on :${p%%:*}"; fi
    done
    local_db_ready && ok "PostgreSQL reachable" || warn "PostgreSQL not reachable"
}

cmd_local() {
    local action="${1:-start}"
    case "$action" in
        setup)  local_setup;;
        start|up) local_start;;
        stop|down) local_stop;;
        status) local_status;;
        *) die "Unknown local action '$action'. Try: setup start stop status";;
    esac
}

usage() {
    sed -n '2,13p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

case "${1:-help}" in
    docker) shift; cmd_docker "$@";;
    local)  shift; cmd_local "$@";;
    help|-h|--help) usage;;
    *) usage; exit 1;;
esac
