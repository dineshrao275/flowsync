#!/usr/bin/env bash
# Provision the AWS side of the single-EC2 deployment (idempotent; safe to re-run).
#
#   deploy/aws/provision.sh iam      run ONCE with admin/root credentials: creates the scoped
#                                    `flowsync-deployer` IAM user and stores its keys as the
#                                    local AWS profile `flowsync-deploy`
#   deploy/aws/provision.sh infra    run with the deploy profile: key pair, security group,
#                                    EC2 instance, Elastic IP (prints the public address)
#   deploy/aws/provision.sh status   show what exists
#   deploy/aws/provision.sh destroy  terminate the instance and release everything it created
#
# Settings (override with environment variables): AWS_REGION, INSTANCE_TYPE, DATA_GB, NAME.
set -euo pipefail

REGION="${AWS_REGION:-ap-south-1}"
NAME="${NAME:-flowsync}"
INSTANCE_TYPE="${INSTANCE_TYPE:-c7i-flex.large}"
DATA_GB="${DATA_GB:-40}"
PROFILE_DEPLOY="flowsync-deploy"
STATE_DIR="${HOME}/.flowsync-aws"
KEY_FILE="${STATE_DIR}/${NAME}.pem"
mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

aws_() { aws --region "$REGION" "$@"; }
tag_spec() { echo "ResourceType=$1,Tags=[{Key=Name,Value=${NAME}},{Key=Project,Value=flowsync}]"; }

cmd_iam() {
  local user=flowsync-deployer policy=FlowSyncDeployer account
  account="$(aws sts get-caller-identity --query Account --output text)"

  cat > "${STATE_DIR}/policy.json" <<POLICY
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "Ec2InOneRegion",
      "Effect": "Allow",
      "Action": "ec2:*",
      "Resource": "*",
      "Condition": { "StringEquals": { "aws:RequestedRegion": "${REGION}" } }
    },
    { "Sid": "Whoami", "Effect": "Allow", "Action": "sts:GetCallerIdentity", "Resource": "*" }
  ]
}
POLICY

  if ! aws iam get-policy --policy-arn "arn:aws:iam::${account}:policy/${policy}" >/dev/null 2>&1; then
    aws iam create-policy --policy-name "$policy" --policy-document "file://${STATE_DIR}/policy.json" >/dev/null
  fi
  aws iam get-user --user-name "$user" >/dev/null 2>&1 || aws iam create-user --user-name "$user" --tags Key=Project,Value=flowsync >/dev/null
  aws iam attach-user-policy --user-name "$user" --policy-arn "arn:aws:iam::${account}:policy/${policy}"

  if aws configure get aws_access_key_id --profile "$PROFILE_DEPLOY" >/dev/null 2>&1; then
    echo "profile ${PROFILE_DEPLOY} already configured; not creating another key"
    return
  fi
  local keys id secret
  keys="$(aws iam create-access-key --user-name "$user" --query 'AccessKey.[AccessKeyId,SecretAccessKey]' --output text)"
  id="$(echo "$keys" | cut -f1)"; secret="$(echo "$keys" | cut -f2)"
  aws configure set aws_access_key_id "$id" --profile "$PROFILE_DEPLOY"
  aws configure set aws_secret_access_key "$secret" --profile "$PROFILE_DEPLOY"
  aws configure set region "$REGION" --profile "$PROFILE_DEPLOY"
  echo "created IAM user ${user} (EC2 in ${REGION} only) and local profile ${PROFILE_DEPLOY}"
}

find_instance() {
  aws_ ec2 describe-instances --filters "Name=tag:Name,Values=${NAME}" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
    --query 'Reservations[].Instances[].InstanceId' --output text
}

cmd_infra() {
  export AWS_PROFILE="${AWS_PROFILE:-$PROFILE_DEPLOY}"
  local vpc sg myip ami iid eip alloc

  vpc="$(aws_ ec2 describe-vpcs --filters Name=isDefault,Values=true --query 'Vpcs[0].VpcId' --output text)"
  [[ "$vpc" != "None" ]] || { echo "no default VPC in ${REGION}" >&2; exit 1; }

  if [[ ! -f "$KEY_FILE" ]]; then
    aws_ ec2 describe-key-pairs --key-names "$NAME" >/dev/null 2>&1 && aws_ ec2 delete-key-pair --key-name "$NAME"
    aws_ ec2 create-key-pair --key-name "$NAME" --key-type ed25519 --query KeyMaterial --output text > "$KEY_FILE"
    chmod 600 "$KEY_FILE"
  fi

  sg="$(aws_ ec2 describe-security-groups --filters "Name=group-name,Values=${NAME}" "Name=vpc-id,Values=${vpc}" --query 'SecurityGroups[0].GroupId' --output text)"
  if [[ "$sg" == "None" ]]; then
    sg="$(aws_ ec2 create-security-group --group-name "$NAME" --description "FlowSync web + admin SSH" --vpc-id "$vpc" \
      --tag-specifications "$(tag_spec security-group)" --query GroupId --output text)"
    aws_ ec2 authorize-security-group-ingress --group-id "$sg" --protocol tcp --port 80 --cidr 0.0.0.0/0 >/dev/null
  fi
  myip="$(curl -fsS https://checkip.amazonaws.com | tr -d '[:space:]')"
  aws_ ec2 authorize-security-group-ingress --group-id "$sg" --protocol tcp --port 22 --cidr "${myip}/32" >/dev/null 2>&1 || true

  iid="$(find_instance)"
  if [[ -z "$iid" ]]; then
    ami="$(aws_ ec2 describe-images --owners 099720109477 \
      --filters 'Name=name,Values=ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*' 'Name=state,Values=available' \
      --query 'sort_by(Images,&CreationDate)[-1].ImageId' --output text)"
    iid="$(aws_ ec2 run-instances --image-id "$ami" --instance-type "$INSTANCE_TYPE" --key-name "$NAME" --security-group-ids "$sg" \
      --block-device-mappings "DeviceName=/dev/sda1,Ebs={VolumeSize=${DATA_GB},VolumeType=gp3,DeleteOnTermination=true,Encrypted=true}" \
      --metadata-options 'HttpTokens=required,HttpEndpoint=enabled' \
      --tag-specifications "$(tag_spec instance)" "$(tag_spec volume)" --query 'Instances[0].InstanceId' --output text)"
  fi
  aws_ ec2 wait instance-running --instance-ids "$iid"

  eip="$(aws_ ec2 describe-addresses --filters "Name=tag:Name,Values=${NAME}" --query 'Addresses[0].PublicIp' --output text)"
  if [[ "$eip" == "None" ]]; then
    alloc="$(aws_ ec2 allocate-address --domain vpc --tag-specifications "$(tag_spec elastic-ip)" --query AllocationId --output text)"
    aws_ ec2 associate-address --instance-id "$iid" --allocation-id "$alloc" >/dev/null
    eip="$(aws_ ec2 describe-addresses --allocation-ids "$alloc" --query 'Addresses[0].PublicIp' --output text)"
  fi
  printf '%s\n' "$eip" > "${STATE_DIR}/${NAME}.host"
  echo "instance ${iid}  address ${eip}  ssh key ${KEY_FILE}"
}

cmd_status() {
  export AWS_PROFILE="${AWS_PROFILE:-$PROFILE_DEPLOY}"
  aws_ ec2 describe-instances --filters "Name=tag:Name,Values=${NAME}" --query 'Reservations[].Instances[].[InstanceId,State.Name,InstanceType,PublicIpAddress]' --output table
}

cmd_destroy() {
  export AWS_PROFILE="${AWS_PROFILE:-$PROFILE_DEPLOY}"
  local iid alloc sg
  iid="$(find_instance)"
  [[ -z "$iid" ]] || { aws_ ec2 terminate-instances --instance-ids "$iid" >/dev/null; aws_ ec2 wait instance-terminated --instance-ids "$iid"; }
  for alloc in $(aws_ ec2 describe-addresses --filters "Name=tag:Name,Values=${NAME}" --query 'Addresses[].AllocationId' --output text); do
    aws_ ec2 release-address --allocation-id "$alloc"
  done
  sg="$(aws_ ec2 describe-security-groups --filters "Name=group-name,Values=${NAME}" --query 'SecurityGroups[0].GroupId' --output text)"
  [[ "$sg" == "None" ]] || aws_ ec2 delete-security-group --group-id "$sg"
  aws_ ec2 delete-key-pair --key-name "$NAME" 2>/dev/null || true
  echo "destroyed (the local key file ${KEY_FILE} is kept; delete it by hand)"
}

case "${1:-}" in
  iam) cmd_iam ;;
  infra) cmd_infra ;;
  status) cmd_status ;;
  destroy) cmd_destroy ;;
  *) sed -n 2,14p "$0"; exit 1 ;;
esac
