#!/usr/bin/env bash
# Deterministic route inventory (method, uri, name, action, middleware) used as a
# regression oracle for behaviour-preserving refactors.
#   scripts/route-snapshot.sh            print snapshot
#   scripts/route-snapshot.sh --check    diff against the committed baseline
#   scripts/route-snapshot.sh --update   rewrite the baseline (only for intended route changes)
set -euo pipefail
cd "$(dirname "$0")/.."
BASE=.agents/roadmap/snapshots/routes.txt

snapshot() {
  php artisan route:list --json | python3 -c '
import json, sys
rows = json.load(sys.stdin)
out = sorted(
    "\t".join([r["method"], r["uri"], r.get("name") or "", r.get("action") or "", ",".join(r.get("middleware") or [])])
    for r in rows
)
print("\n".join(out))
'
}

case "${1:-}" in
  --update) snapshot > "$BASE"; echo "baseline updated: $(wc -l < "$BASE") routes" ;;
  --check)  diff <(snapshot) "$BASE" && echo "route snapshot unchanged" ;;
  *)        snapshot ;;
esac
