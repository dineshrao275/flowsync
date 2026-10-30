#!/usr/bin/env bash
# One-time AWS setup for GitHub Actions -> EC2 continuous deployment. Run with ADMIN credentials
# (it creates IAM resources); idempotent, safe to re-run. Prints the values the workflow uses.
#
#   deploy/aws/setup-cicd.sh [github-owner/repo]      default: the `origin` remote
#
# Creates (no long-lived keys anywhere):
#   * S3 bucket (private, encrypted, releases expire after 7 days) that receives each release tarball
#   * EC2 instance profile with AmazonSSMManagedInstanceCore, so the instance can take SSM commands
#   * GitHub OIDC identity provider + role `flowsync-github-deploy`, assumable ONLY by this repo's
#     master branch, allowed to: put objects in the bucket, run ONE SSM document on ONE instance,
#     read the command result.
set -euo pipefail

REGION="${AWS_REGION:-ap-south-1}"
NAME="${NAME:-flowsync}"
REPO="${1:-$(git config --get remote.origin.url | sed -E 's#(git@github.com:|https://github.com/)##; s#\.git$##')}"
ACCOUNT="$(aws sts get-caller-identity --query Account --output text)"
BUCKET="${NAME}-deploy-${ACCOUNT}-${REGION}"
ROLE="${NAME}-github-deploy"
EC2_ROLE="${NAME}-ec2-ssm"
PROFILE="${NAME}-ec2-ssm"
export AWS_DEFAULT_REGION="$REGION"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

INSTANCE_ID="$(aws ec2 describe-instances --filters "Name=tag:Name,Values=${NAME}" "Name=instance-state-name,Values=pending,running,stopped" \
  --query 'Reservations[0].Instances[0].InstanceId' --output text)"
[[ "$INSTANCE_ID" != "None" ]] || { echo "no instance tagged Name=${NAME}; run provision.sh infra first" >&2; exit 1; }

echo "== S3 bucket ${BUCKET}"
if ! aws s3api head-bucket --bucket "$BUCKET" 2>/dev/null; then
  aws s3api create-bucket --bucket "$BUCKET" --create-bucket-configuration "LocationConstraint=${REGION}" >/dev/null
fi
aws s3api put-public-access-block --bucket "$BUCKET" --public-access-block-configuration \
  BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
aws s3api put-bucket-encryption --bucket "$BUCKET" --server-side-encryption-configuration \
  '{"Rules":[{"ApplyServerSideEncryptionByDefault":{"SSEAlgorithm":"AES256"}}]}'
aws s3api put-bucket-lifecycle-configuration --bucket "$BUCKET" --lifecycle-configuration \
  '{"Rules":[{"ID":"expire-releases","Status":"Enabled","Filter":{"Prefix":"releases/"},"Expiration":{"Days":7},"AbortIncompleteMultipartUpload":{"DaysAfterInitiation":1}}]}'

echo "== instance profile ${PROFILE} (SSM)"
cat > "$tmp/ec2-trust.json" <<JSON
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}
JSON
aws iam get-role --role-name "$EC2_ROLE" >/dev/null 2>&1 || aws iam create-role --role-name "$EC2_ROLE" --assume-role-policy-document "file://$tmp/ec2-trust.json" >/dev/null
aws iam attach-role-policy --role-name "$EC2_ROLE" --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam get-instance-profile --instance-profile-name "$PROFILE" >/dev/null 2>&1 || {
  aws iam create-instance-profile --instance-profile-name "$PROFILE" >/dev/null
  aws iam add-role-to-instance-profile --instance-profile-name "$PROFILE" --role-name "$EC2_ROLE"
  sleep 10  # IAM propagation before the association
}
current="$(aws ec2 describe-iam-instance-profile-associations --filters "Name=instance-id,Values=${INSTANCE_ID}" "Name=state,Values=associated" --query 'IamInstanceProfileAssociations[0].IamInstanceProfile.Arn' --output text)"
if [[ "$current" == "None" ]]; then
  aws ec2 associate-iam-instance-profile --instance-id "$INSTANCE_ID" --iam-instance-profile "Name=${PROFILE}" >/dev/null
fi

echo "== GitHub OIDC provider and role ${ROLE} (repo ${REPO}, branch master only)"
OIDC_ARN="arn:aws:iam::${ACCOUNT}:oidc-provider/token.actions.githubusercontent.com"
aws iam get-open-id-connect-provider --open-id-connect-provider-arn "$OIDC_ARN" >/dev/null 2>&1 || \
  aws iam create-open-id-connect-provider --url https://token.actions.githubusercontent.com --client-id-list sts.amazonaws.com \
    --thumbprint-list 6938fd4d98bab03faadb97b34396831e3780aea1 1c58a3a8518e8759bf075b76b750d4f2df264fcd >/dev/null
cat > "$tmp/gh-trust.json" <<JSON
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Federated":"${OIDC_ARN}"},"Action":"sts:AssumeRoleWithWebIdentity",
 "Condition":{"StringEquals":{"token.actions.githubusercontent.com:aud":"sts.amazonaws.com","token.actions.githubusercontent.com:sub":"repo:${REPO}:ref:refs/heads/master"}}}]}
JSON
if aws iam get-role --role-name "$ROLE" >/dev/null 2>&1; then
  aws iam update-assume-role-policy --role-name "$ROLE" --policy-document "file://$tmp/gh-trust.json"
else
  aws iam create-role --role-name "$ROLE" --assume-role-policy-document "file://$tmp/gh-trust.json" --max-session-duration 3600 >/dev/null
fi
cat > "$tmp/gh-policy.json" <<JSON
{"Version":"2012-10-17","Statement":[
 {"Sid":"UploadRelease","Effect":"Allow","Action":["s3:PutObject","s3:GetObject"],"Resource":"arn:aws:s3:::${BUCKET}/releases/*"},
 {"Sid":"RunDeployOnTheInstance","Effect":"Allow","Action":"ssm:SendCommand","Resource":[
   "arn:aws:ec2:${REGION}:${ACCOUNT}:instance/${INSTANCE_ID}","arn:aws:ssm:${REGION}::document/AWS-RunShellScript"]},
 {"Sid":"ReadResult","Effect":"Allow","Action":["ssm:GetCommandInvocation","ssm:ListCommandInvocations"],"Resource":"*"}
]}
JSON
aws iam put-role-policy --role-name "$ROLE" --policy-name deploy --policy-document "file://$tmp/gh-policy.json"

cat <<OUT

Done. These must match the env block in .github/workflows/deploy.yml:
  AWS_REGION   = ${REGION}
  AWS_ROLE_ARN = arn:aws:iam::${ACCOUNT}:role/${ROLE}
  BUCKET       = ${BUCKET}
  INSTANCE_ID  = ${INSTANCE_ID}
OUT
