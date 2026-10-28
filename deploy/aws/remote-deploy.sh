#!/usr/bin/env bash
# Runs ON the EC2 host (as root, via SSM from CI or over SSH) to roll out one release.
#
#   remote-deploy.sh <release.tgz | https-url> <git-sha>
#
# 1. fetch + unpack the release   2. dump all databases   3. keep the previous image and source
# 4. swap the source, rebuild, restart   5. wait for health   6. on failure, roll back and exit 1.
#
# Secrets (deploy/aws/.env.production) are never part of a release and are never touched here.
# The first start of a new app container runs migrations and `tenants:provision` (docker/entrypoint.sh).
set -euo pipefail

SRC="${1:?release tarball path or URL}"
SHA="${2:?git sha}"
APP=/opt/flowsync
STAGE=/opt/flowsync-release
PREV=/opt/flowsync-prev
BACKUPS=/opt/backups
LOCK=/var/lock/flowsync-deploy.lock

exec 9>"$LOCK"
flock -n 9 || { echo "another deploy is running" >&2; exit 1; }

compose() { (cd "$APP/deploy/aws" && docker compose -f docker-compose.prod.yml --env-file .env.production "$@"); }
healthy() { curl -fsS --max-time 5 http://localhost/up >/dev/null 2>&1; }

echo "== fetching release ${SHA}"
tgz=/tmp/flowsync-release.tgz
if [[ "$SRC" == http* ]]; then curl -fsSL --retry 3 "$SRC" -o "$tgz"; else cp "$SRC" "$tgz"; fi
rm -rf "$STAGE" && mkdir -p "$STAGE"
tar -xzf "$tgz" -C "$STAGE"
[[ -f "$STAGE/Dockerfile" && -f "$STAGE/deploy/aws/docker-compose.prod.yml" ]] || { echo "release is missing deploy files" >&2; exit 1; }
[[ -f "$APP/deploy/aws/.env.production" ]] || { echo "$APP/deploy/aws/.env.production is missing; run deploy.sh once from a workstation" >&2; exit 1; }

echo "== backing up databases"
mkdir -p "$BACKUPS"
if compose ps --status running --services 2>/dev/null | grep -qx db; then
  out="$BACKUPS/pre-${SHA:0:12}-$(date +%Y%m%d-%H%M%S).sql.gz"
  compose exec -T db pg_dumpall -U flowsync | gzip > "$out"
  [[ -s "$out" ]] || { echo "database backup is empty; aborting before any change" >&2; rm -f "$out"; exit 1; }
  echo "   $out ($(du -h "$out" | cut -f1))"
  ls -1t "$BACKUPS"/pre-*.sql.gz | tail -n +6 | xargs -r rm -f
fi

echo "== keeping the previous version for rollback"
docker tag flowsync:prod flowsync:prev 2>/dev/null || true
rm -rf "$PREV" && cp -a "$APP" "$PREV"

echo "== swapping source"
rsync -a --delete --exclude 'deploy/aws/.env.production' --exclude '.deployed-sha' "$STAGE/" "$APP/"

rollback() {
  echo "!! deploy failed; rolling back" >&2
  if docker image inspect flowsync:prev >/dev/null 2>&1; then docker tag flowsync:prev flowsync:prod; fi
  rsync -a --delete --exclude 'deploy/aws/.env.production' "$PREV/" "$APP/"
  compose up -d --no-build --remove-orphans || true
  compose exec -T proxy caddy reload --config /etc/caddy/Caddyfile >/dev/null 2>&1 || compose restart proxy || true
}

echo "== building and restarting"
if ! compose up -d --build --remove-orphans; then rollback; exit 1; fi
compose exec -T proxy caddy reload --config /etc/caddy/Caddyfile >/dev/null 2>&1 || compose restart proxy

echo "== waiting for the app to answer"
for _ in $(seq 1 60); do
  if healthy; then ok=1; break; fi
  sleep 5
done
if [[ -z "${ok:-}" ]]; then rollback; exit 1; fi

echo "$SHA" > "$APP/.deployed-sha"
docker image prune -f >/dev/null 2>&1 || true
rm -rf "$STAGE" "$tgz"
compose ps
echo "== deployed ${SHA}"
