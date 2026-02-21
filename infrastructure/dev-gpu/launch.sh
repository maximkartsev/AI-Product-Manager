#!/bin/bash
# Launch a dev ComfyUI GPU instance using the production AMI.
# Usage: ./launch.sh
# Configuration via environment variables (see README.md).
set -eu
# Some environments may run an older shell that doesn't support `pipefail`.
set -o pipefail 2>/dev/null || true

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE_FILE="$HOME/.comfyui-dev-instance"

# ── Configuration (env vars with defaults) ───────────────────────────────────
AWS_REGION_INPUT="${AWS_REGION:-}"
INSTANCE_TYPE="${INSTANCE_TYPE:-g4dn.xlarge}"
AMI_ID="${AMI_ID:-}"
AMI_SSM_PARAM="${AMI_SSM_PARAM:-}"
KEY_NAME="${KEY_NAME:-}"
INSTANCE_PROFILE="${INSTANCE_PROFILE:-}"
AUTO_SHUTDOWN_HOURS="${AUTO_SHUTDOWN_HOURS:-4}"
VOLUME_SIZE="${VOLUME_SIZE:-100}"
STAGE_INPUT="${STAGE:-}"
FLEET_SLUG_INPUT="${FLEET_SLUG:-}"
WORKFLOW_SLUG_INPUT="${WORKFLOW_SLUG:-}"
WORKFLOW_SLUG="${WORKFLOW_SLUG_INPUT:-image-to-video}"
FLEET_SLUG="${FLEET_SLUG_INPUT:-gpu-default}"
STAGE="${STAGE_INPUT:-staging}"
SG_NAME_BASE="comfyui-dev-sg"

echo "=== ComfyUI Dev GPU Launcher ==="

# ── Pre-flight checks ───────────────────────────────────────────────────────
if ! command -v aws &>/dev/null; then
  echo "ERROR: AWS CLI not found. Install it first: https://docs.aws.amazon.com/cli/latest/userguide/install-cliv2.html"
  exit 1
fi

# ── Load state (if any) ─────────────────────────────────────────────────────
if [ -f "$STATE_FILE" ]; then
  # shellcheck disable=SC1090
  source "$STATE_FILE"
fi

AWS_REGION="${AWS_REGION_INPUT:-us-east-1}"
if [ -z "$AWS_REGION_INPUT" ] && [ -n "${DEVGPU_AWS_REGION:-}" ]; then
  AWS_REGION="$DEVGPU_AWS_REGION"
fi
if [ -z "$STAGE_INPUT" ] && [ -n "${DEVGPU_STAGE:-}" ]; then
  STAGE="$DEVGPU_STAGE"
fi
if [ -z "$FLEET_SLUG_INPUT" ] && [ -n "${DEVGPU_FLEET_SLUG:-}" ]; then
  FLEET_SLUG="$DEVGPU_FLEET_SLUG"
fi
if [ -z "$WORKFLOW_SLUG_INPUT" ] && [ -n "${DEVGPU_WORKFLOW_SLUG:-}" ]; then
  WORKFLOW_SLUG="$DEVGPU_WORKFLOW_SLUG"
fi

get_instance_state() {
  aws ec2 describe-instances \
    --region "$AWS_REGION" \
    --instance-ids "$1" \
    --query 'Reservations[0].Instances[0].State.Name' \
    --output text 2>/dev/null || echo "not-found"
}

get_public_ip() {
  aws ec2 describe-instances \
    --region "$AWS_REGION" \
    --instance-ids "$1" \
    --query 'Reservations[0].Instances[0].PublicIpAddress' \
    --output text 2>/dev/null || echo "N/A"
}

configure_sg_ingress() {
  local sg_id="$1"
  local instance_id="${2:-}"
  local allow_ssh="${3:-}"

  local users safe
  safe=true
  users=$(aws ec2 describe-instances \
    --region "$AWS_REGION" \
    --filters "Name=instance.group-id,Values=$sg_id" \
              "Name=instance-state-name,Values=pending,running,stopping,stopped,shutting-down" \
    --query 'Reservations[].Instances[].InstanceId' \
    --output text 2>/dev/null | tr -s '[:space:]')

  if [ -n "$users" ] && [ "$users" != "None" ]; then
    for id in $users; do
      if [ -n "$instance_id" ] && [ "$id" = "$instance_id" ]; then
        continue
      fi
      safe=false
      break
    done
  fi

  if [ "$safe" = true ]; then
    local existing
    existing=$(aws ec2 describe-security-groups \
      --region "$AWS_REGION" \
      --group-ids "$sg_id" \
      --query 'SecurityGroups[0].IpPermissions' \
      --output json)
    if [ "$existing" != "[]" ]; then
      aws ec2 revoke-security-group-ingress \
        --region "$AWS_REGION" \
        --group-id "$sg_id" \
        --ip-permissions "$existing" >/dev/null 2>&1 || true
    fi
  else
    echo "WARNING: Security group $sg_id is attached to other instances; preserving existing ingress rules."
  fi

  aws ec2 authorize-security-group-ingress \
    --region "$AWS_REGION" \
    --group-id "$sg_id" \
    --protocol tcp \
    --port 8188 \
    --cidr "$MY_IP/32" >/dev/null 2>&1 || true

  if [ -n "$allow_ssh" ]; then
    aws ec2 authorize-security-group-ingress \
      --region "$AWS_REGION" \
      --group-id "$sg_id" \
      --protocol tcp \
      --port 22 \
      --cidr "$MY_IP/32" >/dev/null 2>&1 || true
  fi
}

save_state() {
  cat > "$STATE_FILE" <<EOF
DEVGPU_INSTANCE_ID="$INSTANCE_ID"
DEVGPU_AWS_REGION="$AWS_REGION"
DEVGPU_SG_ID="$SG_ID"
DEVGPU_SG_NAME="$SG_NAME"
DEVGPU_VPC_ID="$VPC_ID"
DEVGPU_SUBNET_ID="$SUBNET_ID"
DEVGPU_STAGE="$STAGE"
DEVGPU_FLEET_SLUG="$FLEET_SLUG"
DEVGPU_WORKFLOW_SLUG="$WORKFLOW_SLUG"
DEVGPU_INSTANCE_TYPE="$INSTANCE_TYPE"
EOF
}

# ── Detect public IP ────────────────────────────────────────────────────────
echo "Detecting your public IP..."
MY_IP=$(curl -s --max-time 5 https://checkip.amazonaws.com | tr -d '[:space:]')
if [ -z "$MY_IP" ]; then
  echo "ERROR: Could not detect public IP. Check your internet connection."
  exit 1
fi
echo "Your IP: $MY_IP"

# ── Resume or show existing instance (if any) ───────────────────────────────
EXISTING_ID="${DEVGPU_INSTANCE_ID:-}"
if [ -n "$EXISTING_ID" ]; then
  EXISTING_STATE=$(get_instance_state "$EXISTING_ID")

  if [[ "$EXISTING_STATE" == "running" || "$EXISTING_STATE" == "pending" ]]; then
    EXISTING_IP=$(get_public_ip "$EXISTING_ID")
    echo "Dev instance already running."
    echo "  Instance: $EXISTING_ID ($EXISTING_STATE)"
    echo "  IP:       $EXISTING_IP"
    echo "  URL:      http://$EXISTING_IP:8188"
    if [ -n "${DEVGPU_SG_ID:-}" ]; then
      configure_sg_ingress "$DEVGPU_SG_ID" "$EXISTING_ID" "$KEY_NAME"
    fi
    exit 0
  fi

  if [[ "$EXISTING_STATE" == "stopping" || "$EXISTING_STATE" == "shutting-down" ]]; then
    echo "Instance is $EXISTING_STATE. Waiting for it to stop..."
    aws ec2 wait instance-stopped --region "$AWS_REGION" --instance-ids "$EXISTING_ID" 2>/dev/null || true
    EXISTING_STATE=$(get_instance_state "$EXISTING_ID")
  fi

  if [[ "$EXISTING_STATE" == "stopped" ]]; then
    echo "Starting stopped instance $EXISTING_ID..."
    aws ec2 start-instances --region "$AWS_REGION" --instance-ids "$EXISTING_ID" >/dev/null
    aws ec2 wait instance-running --region "$AWS_REGION" --instance-ids "$EXISTING_ID"
    EXISTING_IP=$(get_public_ip "$EXISTING_ID")
    if [ -n "${DEVGPU_SG_ID:-}" ]; then
      configure_sg_ingress "$DEVGPU_SG_ID" "$EXISTING_ID" "$KEY_NAME"
    fi
    echo ""
    echo "========================================"
    echo "  ComfyUI Dev Instance Ready"
    echo "========================================"
    echo "  Instance ID : $EXISTING_ID"
    echo "  Public IP   : $EXISTING_IP"
    echo "  ComfyUI URL : http://$EXISTING_IP:8188"
    if [ -n "$KEY_NAME" ]; then
      echo "  SSH          : ssh -i ~/.ssh/$KEY_NAME.pem ubuntu@$EXISTING_IP"
    fi
    echo "========================================"
    exit 0
  fi

  if [[ "$EXISTING_STATE" == "terminated" || "$EXISTING_STATE" == "not-found" ]]; then
    rm -f "$STATE_FILE"
  fi
fi

# ── Resolve AMI ──────────────────────────────────────────────────────────────
if [ -z "$AMI_ID" ]; then
  if [ -n "$AMI_SSM_PARAM" ]; then
    AMI_PARAM="$AMI_SSM_PARAM"
  elif [ -n "$FLEET_SLUG" ]; then
    AMI_PARAM="/bp/ami/fleets/${STAGE}/${FLEET_SLUG}"
  else
    AMI_PARAM="/bp/ami/${WORKFLOW_SLUG}"
  fi

  echo "Resolving AMI from SSM parameter ${AMI_PARAM}..."
  AMI_ID=$(aws ssm get-parameter \
    --region "$AWS_REGION" \
    --name "$AMI_PARAM" \
    --query 'Parameter.Value' \
    --output text 2>/dev/null) || true

  if [ -z "$AMI_ID" ]; then
    echo "ERROR: Could not resolve AMI. Set AMI_ID or AMI_SSM_PARAM, or create the expected SSM parameter."
    exit 1
  fi
fi
echo "AMI: $AMI_ID"

# ── Default VPC ──────────────────────────────────────────────────────────────
echo "Finding default VPC..."
VPC_ID=$(aws ec2 describe-vpcs \
  --region "$AWS_REGION" \
  --filters "Name=isDefault,Values=true" \
  --query 'Vpcs[0].VpcId' \
  --output text)

if [ "$VPC_ID" = "None" ] || [ -z "$VPC_ID" ]; then
  echo "ERROR: No default VPC found in $AWS_REGION. Create one with: aws ec2 create-default-vpc"
  exit 1
fi

# Pick a public subnet in the default VPC
SUBNET_ID=$(aws ec2 describe-subnets \
  --region "$AWS_REGION" \
  --filters "Name=vpc-id,Values=$VPC_ID" "Name=default-for-az,Values=true" \
  --query 'Subnets[0].SubnetId' \
  --output text)

# ── Security group ───────────────────────────────────────────────────────────
SG_NAME="$SG_NAME_BASE"
SG_ID=$(aws ec2 describe-security-groups \
  --region "$AWS_REGION" \
  --filters "Name=group-name,Values=$SG_NAME" "Name=vpc-id,Values=$VPC_ID" "Name=tag:ManagedBy,Values=dev-gpu-scripts" \
  --query 'SecurityGroups[0].GroupId' \
  --output text 2>/dev/null || echo "None")

if [ "$SG_ID" = "None" ] || [ -z "$SG_ID" ]; then
  # If a non-managed SG already uses the name, create a unique one.
  EXISTING_SG=$(aws ec2 describe-security-groups \
    --region "$AWS_REGION" \
    --filters "Name=group-name,Values=$SG_NAME" "Name=vpc-id,Values=$VPC_ID" \
    --query 'SecurityGroups[0].GroupId' \
    --output text 2>/dev/null || echo "None")
  if [ "$EXISTING_SG" != "None" ] && [ -n "$EXISTING_SG" ]; then
    SG_NAME="${SG_NAME_BASE}-$(date +%s)"
  fi

  echo "Creating security group $SG_NAME..."
  SG_ID=$(aws ec2 create-security-group \
    --region "$AWS_REGION" \
    --group-name "$SG_NAME" \
    --description "ComfyUI dev instance - managed by dev-gpu scripts" \
    --vpc-id "$VPC_ID" \
    --query 'GroupId' \
    --output text)

  aws ec2 create-tags \
    --region "$AWS_REGION" \
    --resources "$SG_ID" \
    --tags Key=Name,Value="$SG_NAME" Key=ManagedBy,Value=dev-gpu-scripts Key=WorkflowSlug,Value="$WORKFLOW_SLUG"
else
  echo "Reusing managed security group $SG_ID..."
fi

configure_sg_ingress "$SG_ID" "" "$KEY_NAME"

echo "  Security group $SG_ID configured for $MY_IP"

# ── Prepare user-data ───────────────────────────────────────────────────────
USER_DATA=$(sed "s/__AUTO_SHUTDOWN_HOURS__/$AUTO_SHUTDOWN_HOURS/g" "$SCRIPT_DIR/user-data.sh")
USER_DATA_B64=$(echo "$USER_DATA" | base64 -w 0 2>/dev/null || echo "$USER_DATA" | base64 | tr -d '\n')

# ── Build launch params ─────────────────────────────────────────────────────
LAUNCH_ARGS=(
  --region "$AWS_REGION"
  --image-id "$AMI_ID"
  --instance-type "$INSTANCE_TYPE"
  --security-group-ids "$SG_ID"
  --subnet-id "$SUBNET_ID"
  --associate-public-ip-address
  --user-data "$USER_DATA_B64"
  --metadata-options "HttpTokens=required,HttpEndpoint=enabled,HttpPutResponseHopLimit=1"
  --instance-initiated-shutdown-behavior stop
  --block-device-mappings "DeviceName=/dev/sda1,Ebs={VolumeSize=$VOLUME_SIZE,VolumeType=gp3,DeleteOnTermination=true,Encrypted=true}"
  --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=comfyui-dev},{Key=ManagedBy,Value=dev-gpu-scripts},{Key=Stage,Value=$STAGE},{Key=FleetSlug,Value=$FLEET_SLUG},{Key=WorkflowSlug,Value=$WORKFLOW_SLUG}]"
  --count 1
  --query 'Instances[0].InstanceId'
  --output text
)

if [ -n "$KEY_NAME" ]; then
  LAUNCH_ARGS+=(--key-name "$KEY_NAME")
fi
if [ -n "$INSTANCE_PROFILE" ]; then
  if [[ "$INSTANCE_PROFILE" == arn:* ]]; then
    LAUNCH_ARGS+=(--iam-instance-profile "Arn=$INSTANCE_PROFILE")
  else
    LAUNCH_ARGS+=(--iam-instance-profile "Name=$INSTANCE_PROFILE")
  fi
fi

# ── Launch ───────────────────────────────────────────────────────────────────
echo ""
echo "Launching $INSTANCE_TYPE instance..."
INSTANCE_ID=$(aws ec2 run-instances "${LAUNCH_ARGS[@]}")

echo "Instance $INSTANCE_ID launched. Waiting for running state..."
aws ec2 wait instance-running --region "$AWS_REGION" --instance-ids "$INSTANCE_ID"

# Get public IP
PUBLIC_IP=$(get_public_ip "$INSTANCE_ID")

save_state

# ── Print summary ────────────────────────────────────────────────────────────
echo ""
echo "========================================"
echo "  ComfyUI Dev Instance Ready"
echo "========================================"
echo "  Instance ID : $INSTANCE_ID"
echo "  Public IP   : $PUBLIC_IP"
echo "  ComfyUI URL : http://$PUBLIC_IP:8188"
if [ -n "$KEY_NAME" ]; then
  echo "  SSH          : ssh -i ~/.ssh/$KEY_NAME.pem ubuntu@$PUBLIC_IP"
fi
echo ""
echo "  Auto-shutdown: ${AUTO_SHUTDOWN_HOURS}h from boot (instance will stop)"
echo "  Region       : $AWS_REGION"
echo "  Instance type: $INSTANCE_TYPE"
echo "  Stage        : $STAGE"
echo "  Fleet        : $FLEET_SLUG"
echo "  Workflow     : $WORKFLOW_SLUG"
echo "========================================"
echo ""
echo "NOTE: ComfyUI may take 1-2 minutes to start after the instance is running."
echo "      Check /var/log/dev-gpu-setup.log on the instance if it doesn't load."
echo ""
echo "To stop: ./shutdown.sh"
