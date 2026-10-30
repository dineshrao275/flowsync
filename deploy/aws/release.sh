#!/usr/bin/env bash
# Package a commit, hand it to the EC2 instance through S3 + SSM, and wait for the result.
# Used by .github/workflows/deploy.yml; runs the same way from a workstation with AWS credentials.
#
#   BUCKET=... INSTANCE_ID=... [AWS_REGION=ap-south-1] [GIT_SHA=<commit>] deploy/aws/release.sh
#
# The instance fetches the release through a 15-minute presigned URL, so it needs no S3 permissions
# and no GitHub credentials; the runner needs only s3:PutObject and ssm:SendCommand on this one instance.
set -euo pipefail

: "${BUCKET:?set BUCKET}" "${INSTANCE_ID:?set INSTANCE_ID}"
export AWS_DEFAULT_REGION="${AWS_REGION:-ap-south-1}"
SHA="${GIT_SHA:-$(git rev-parse HEAD)}"
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT

echo "== packaging ${SHA}"
mkdir "$work/src"
git archive --format=tar "$SHA" | tar -x -C "$work/src"
tar -czf "$work/release.tgz" -C "$work/src" --exclude=./tests --exclude=./.agents --exclude=./.github --exclude=./docs .
key="releases/${SHA}.tgz"
aws s3 cp "$work/release.tgz" "s3://${BUCKET}/${key}" --only-show-errors
url="$(aws s3 presign "s3://${BUCKET}/${key}" --expires-in 900)"
echo "   uploaded $(du -h "$work/release.tgz" | cut -f1)"

# The script comes from the release itself, so a deploy-script change ships with the code that needs it.
python3 - "$url" "$SHA" "$work/params.json" <<'PY'
import json, sys
url, sha, out = sys.argv[1:4]
script = (
    "set -e; d=$(mktemp -d); "
    f"curl -fsSL --retry 3 '{url}' -o \"$d/release.tgz\"; "
    "tar -xzf \"$d/release.tgz\" -C \"$d\" ./deploy/aws/remote-deploy.sh; "
    f"bash \"$d/deploy/aws/remote-deploy.sh\" \"$d/release.tgz\" {sha}"
)
json.dump({"commands": [script], "executionTimeout": ["1800"]}, open(out, "w"))
PY

echo "== deploying on ${INSTANCE_ID}"
cmd_id="$(aws ssm send-command --instance-ids "$INSTANCE_ID" --document-name AWS-RunShellScript \
  --comment "flowsync ${SHA:0:12}" --timeout-seconds 120 --parameters "file://${work}/params.json" \
  --query Command.CommandId --output text)"

status=InProgress
for _ in $(seq 1 270); do   # up to ~45 minutes
  sleep 10
  status="$(aws ssm get-command-invocation --command-id "$cmd_id" --instance-id "$INSTANCE_ID" --query Status --output text 2>/dev/null || echo Pending)"
  case "$status" in Success|Failed|Cancelled|TimedOut) break ;; esac
done

aws ssm get-command-invocation --command-id "$cmd_id" --instance-id "$INSTANCE_ID" \
  --query '[StandardOutputContent,StandardErrorContent]' --output text | tail -n 60 || true
echo "== SSM command ${cmd_id}: ${status}"
[[ "$status" == "Success" ]]
