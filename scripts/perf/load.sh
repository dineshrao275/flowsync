#!/usr/bin/env bash
# Read-only load baseline for an authenticated tenant user. Needs ApacheBench (ab) and curl.
#
#   BASE=http://localhost:8000 EMAIL=admin@flowsync.test PASSWORD=password \
#   LEVELS="1 10 25 50" REQS=400 scripts/perf/load.sh [endpoint ...]
#
# Prints one CSV line per endpoint x concurrency: rps, p50/p95/p99 (ms), failed, non-2xx.
# GET endpoints only; never run it against a database you care about with a write-heavy mix.
set -euo pipefail
BASE="${BASE:-http://localhost:8000}"; EMAIL="${EMAIL:-admin@flowsync.test}"; PASSWORD="${PASSWORD:-password}"
LEVELS="${LEVELS:-1 10 25 50}"; REQS="${REQS:-400}"
ENDPOINTS=("$@"); [ ${#ENDPOINTS[@]} -gt 0 ] || ENDPOINTS=(auth/me dashboard projects workspaces users notifications "search/global?q=a" "hrms/employees")
jar="$(mktemp)"; trap 'rm -f "$jar"' EXIT
curl -s -c "$jar" -o /dev/null "$BASE/sanctum/csrf-cookie"
xsrf="$(awk '$6=="XSRF-TOKEN"{print $7}' "$jar" | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')"
code="$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$BASE/api/auth/login" -H "X-XSRF-TOKEN: $xsrf" -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")"
[ "$code" = 200 ] || { echo "login failed: HTTP $code" >&2; exit 1; }
cookies=(); while read -r name value; do cookies+=(-C "$name=$value"); done < <(awk '$6!="" && $1!~/^#HttpOnly_?$/ {print $6, $7}' "$jar" | sed 's/^#HttpOnly_//')
echo "endpoint,concurrency,rps,p50_ms,p95_ms,p99_ms,failed,non2xx"
for ep in "${ENDPOINTS[@]}"; do
  for c in $LEVELS; do
    out="$(ab -q -n "$REQS" -c "$c" "${cookies[@]}" -H 'Accept: application/json' "$BASE/api/$ep" 2>&1 || true)"
    rps="$(awk '/Requests per second/{print $4}' <<<"$out")"
    p50="$(awk '$1=="50%"{print $2}' <<<"$out")"; p95="$(awk '$1=="95%"{print $2}' <<<"$out")"; p99="$(awk '$1=="99%"{print $2}' <<<"$out")"
    failed="$(awk '/Failed requests/{print $3}' <<<"$out")"; non2="$(awk '/Non-2xx/{print $3}' <<<"$out")"
    echo "$ep,$c,${rps:-?},${p50:-?},${p95:-?},${p99:-?},${failed:-?},${non2:-0}"
  done
done
