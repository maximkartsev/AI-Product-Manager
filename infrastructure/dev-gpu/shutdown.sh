#!/bin/bash
# Stop or terminate a dev ComfyUI GPU instance and optionally clean up the security group.
# Usage: ./shutdown.sh [--terminate] [--cleanup] [instance-id]
set -euo
set -o pipefail 2>/dev/null || true

AWS_REGION_INPUT="${AWS_REGION:-}"
STATE_FILE="$HOME/.comfyui-dev-instance"
CLEANUP=false
ACTION="stop"
INSTANCE_ID=""
SG_ID=""

# ── Parse arguments ──────────────────────────────────────────────────────────
for arg in "$@"; do
  case "$arg" in
    --cleanup)   CLEANUP=true ;;
    --terminate) ACTION="terminate" ;;
    i-*)         INSTANCE_ID="$arg" ;;
    *)           echo "Usage: ./shutdown.sh [--terminate] [--cleanup] [instance-id]"; exit 1 ;;
  esac
done

# ── Load state ───────────────────────────────────────────────────────────────
if [ -f "$STATE_FILE" ]; then
  # shellcheck disable=SC1090
  source "$STATE_FILE"
fi

AWS_REGION="${AWS_REGION_INPUT:-us-east-1}"
if [ -z "$AWS_REGION_INPUT" ] && [ -n "${DEVGPU_AWS_REGION:-}" ]; then
  AWS_REGION="$DEVGPU_AWS_REGION"
fi

if [ -z "$INSTANCE_ID" ]; then
  INSTANCE_ID="${DEVGPU_INSTANCE_ID:-}"
fi
if [ -z "$SG_ID" ]; then
  SG_ID="${DEVGPU_SG_ID:-}"
fi

if [ "$ACTION" = "stop" ] && [ "$CLEANUP" = true ]; then
  echo "WARNING: --cleanup only applies to --terminate; ignoring."
  CLEANUP=false
fi

# ── Resolve instance ID ─────────────────────────────────────────────────────
if [ -z "$INSTANCE_ID" ]; then
  echo "ERROR: No instance ID provided and no state file found at $STATE_FILE."
  echo "Usage: ./shutdown.sh [--terminate] [--cleanup] [instance-id]"
  exit 1
fi

echo "=== ComfyUI Dev GPU Shutdown ==="

# ── Stop or terminate instance ──────────────────────────────────────────────
CURRENT_STATE=$(aws ec2 describe-instances \
  --region "$AWS_REGION" \
  --instance-ids "$INSTANCE_ID" \
  --query 'Reservations[0].Instances[0].State.Name' \
  --output text 2>/dev/null || echo "not-found")

if [[ "$CURRENT_STATE" == "terminated" || "$CURRENT_STATE" == "not-found" ]]; then
  echo "Instance $INSTANCE_ID is already terminated (state: $CURRENT_STATE)."
  rm -f "$STATE_FILE"
else
  if [ "$ACTION" = "stop" ]; then
    if [[ "$CURRENT_STATE" == "stopped" ]]; then
      echo "Instance $INSTANCE_ID is already stopped."
    else
      echo "Stopping instance $INSTANCE_ID..."
      aws ec2 stop-instances --region "$AWS_REGION" --instance-ids "$INSTANCE_ID" >/dev/null
      echo "Waiting for stop..."
      aws ec2 wait instance-stopped --region "$AWS_REGION" --instance-ids "$INSTANCE_ID" 2>/dev/null || true
      echo "Instance $INSTANCE_ID stopped."
    fi
  else
    echo "Terminating instance $INSTANCE_ID..."
    aws ec2 terminate-instances --region "$AWS_REGION" --instance-ids "$INSTANCE_ID" >/dev/null
    echo "Waiting for termination..."
    aws ec2 wait instance-terminated --region "$AWS_REGION" --instance-ids "$INSTANCE_ID" 2>/dev/null || true
    echo "Instance $INSTANCE_ID terminated."
    rm -f "$STATE_FILE"
  fi
fi

# ── Clean up security group (terminate only) ─────────────────────────────────
if [ "$CLEANUP" = true ] && [ "$ACTION" = "terminate" ]; then
  if [ -z "$SG_ID" ]; then
    SG_ID=$(aws ec2 describe-security-groups \
      --region "$AWS_REGION" \
      --filters "Name=tag:ManagedBy,Values=dev-gpu-scripts" \
      --query 'SecurityGroups[].GroupId' \
      --output text 2>/dev/null | tr -s '[:space:]')
  fi

  if [[ "$SG_ID" == *" "* ]]; then
    echo "Multiple managed security groups found; skipping cleanup. Use state file for a specific SG."
    SG_ID=""
  fi

  if [ -z "$SG_ID" ] || [ "$SG_ID" = "None" ]; then
    echo "No managed security group found — nothing to clean up."
  else
    # Only delete if managed by dev-gpu-scripts and unused
    MANAGED=$(aws ec2 describe-security-groups \
      --region "$AWS_REGION" \
      --group-ids "$SG_ID" \
      --query 'SecurityGroups[0].Tags[?Key==`ManagedBy`].Value | [0]' \
      --output text 2>/dev/null || echo "")
    if [ "$MANAGED" != "dev-gpu-scripts" ]; then
      echo "WARNING: Security group $SG_ID is not managed by dev-gpu-scripts. Skipping deletion."
    else
      USERS=$(aws ec2 describe-instances \
        --region "$AWS_REGION" \
        --filters "Name=instance.group-id,Values=$SG_ID" \
                  "Name=instance-state-name,Values=pending,running,stopping,stopped,shutting-down" \
        --query 'Reservations[].Instances[].InstanceId' \
        --output text 2>/dev/null | tr -s '[:space:]')

      if [ -n "$USERS" ] && [ "$USERS" != "None" ]; then
        echo "WARNING: Security group $SG_ID is still used by: $USERS"
        echo "Skipping deletion."
      else
        aws ec2 delete-security-group --region "$AWS_REGION" --group-id "$SG_ID" 2>/dev/null && \
          echo "Security group $SG_ID deleted." || \
          echo "WARNING: Could not delete security group (may still be referenced). Try again in a few minutes."
      fi
    fi
  fi
fi

echo ""
echo "Done."
