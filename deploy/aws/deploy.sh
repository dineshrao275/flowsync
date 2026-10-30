#!/usr/bin/env bash
# Build and (re)start FlowSync on the EC2 host created by provision.sh. Safe to re-run: it syncs
# the source, keeps the generated secrets, rebuilds the image and restarts changed services.
#
#   deploy/aws/deploy.sh            deploy the working tree
#   deploy/aws/deploy.sh logs [svc] tail service logs
#   deploy/aws/deploy.sh ssh        open a shell on the host
#
# Secrets are generated once into ~/.flowsync-aws/env.production (mode 600, outside the repo).
set -euo pipefail

NAME="${NAME:-flowsync}"
STATE_DIR="${HOME}/.flowsync-aws"
KEY="${STATE_DIR}/${NAME}.pem"
HOST="${HOST:-$(cat "${STATE_DIR}/${NAME}.host")}"
ENV_LOCAL="${STATE_DIR}/env.production"
SSH_OPTS=(-i "$KEY" -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30)
REMOTE="ubuntu@${HOST}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

ssh_() { ssh "${SSH_OPTS[@]}" "$REMOTE" "$@"; }
compose() { ssh_ "cd /opt/flowsync/deploy/aws && sudo docker compose -f docker-compose.prod.yml --env-file .env.production $*"; }

render_env() {
  [[ -f "$ENV_LOCAL" ]] && return
  python3 - "$ROOT/deploy/aws/env.production.template" "$ENV_LOCAL" "$HOST" <<'PY'
import base64, os, secrets, sys
tpl, out, host = sys.argv[1:4]
values = {
    'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
    'DB_PASSWORD': secrets.token_hex(24),
    'REVERB_APP_ID': str(secrets.randbelow(900000) + 100000),
    'REVERB_APP_KEY': secrets.token_hex(10),
    'REVERB_APP_SECRET': secrets.token_hex(20),
    'SUPERADMIN_EMAIL': os.environ.get('SUPERADMIN_EMAIL', 'admin@flowsync.test'),
    'SUPERADMIN_PASSWORD': secrets.token_urlsafe(18),
    'PUBLIC_HOST': host,
}
text = open(tpl).read()
for k, v in values.items():
    text = text.replace('__' + k + '__', v)
open(out, 'w').write(text)
PY
  chmod 600 "$ENV_LOCAL"
  echo "generated ${ENV_LOCAL}"
}

wait_ssh() {
  for _ in $(seq 1 30); do ssh_ true 2>/dev/null && return; sleep 5; done
  echo "cannot reach ${HOST} over SSH" >&2; exit 1
}

bootstrap_host() {
  ssh_ 'bash -s' <<'REMOTE_SH'
set -euo pipefail
if ! command -v docker >/dev/null; then
  sudo apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io docker-compose-v2 rsync
  sudo systemctl enable --now docker
fi
if ! swapon --show | grep -q /swapfile; then
  sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile >/dev/null && sudo swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
fi
sudo mkdir -p /opt/flowsync && sudo chown ubuntu:ubuntu /opt/flowsync
REMOTE_SH
}

sync_source() {
  rsync -az --delete -e "ssh ${SSH_OPTS[*]}" \
    --exclude '.git' --exclude 'vendor' --exclude 'node_modules' --exclude 'public/build' --exclude 'public/hot' \
    --exclude 'storage/logs/*' --exclude 'storage/framework/*' --exclude 'database/tenants' --exclude '.env' --exclude '.env.*' \
    --exclude 'tests' --exclude '.agents' --exclude '.github' --exclude 'docs' --exclude 'deploy/aws/.env.production' \
    "$ROOT/" "${REMOTE}:/opt/flowsync/"
  scp "${SSH_OPTS[@]}" "$ENV_LOCAL" "${REMOTE}:/opt/flowsync/deploy/aws/.env.production" >/dev/null
  ssh_ 'chmod 600 /opt/flowsync/deploy/aws/.env.production'
}

case "${1:-deploy}" in
  logs) compose "logs -f --tail=100 ${2:-}" ;;
  ssh) shift; exec ssh "${SSH_OPTS[@]}" "$REMOTE" "$@" ;;
  deploy)
    render_env
    wait_ssh
    bootstrap_host
    sync_source
    compose "up -d --build --remove-orphans"
    # The proxy reads its config at start; a changed Caddyfile does not recreate the container.
    compose "exec -T proxy caddy reload --config /etc/caddy/Caddyfile" </dev/null || compose "restart proxy" </dev/null
    compose "ps"
    echo "FlowSync: http://${HOST}/app   (first boot takes a few minutes; admin login in ${ENV_LOCAL})"
    ;;
  *) sed -n 2,10p "$0"; exit 1 ;;
esac
