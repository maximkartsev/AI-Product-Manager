#!/usr/bin/env bash
set -euo pipefail

FLEET_SLUG=""
STAGE=""
AWS_REGION="${AWS_REGION:-}"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --fleet-slug)
      FLEET_SLUG="$2"
      shift 2
      ;;
    --stage)
      STAGE="$2"
      shift 2
      ;;
    --region)
      AWS_REGION="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 2
      ;;
  esac
done

if [ -z "$FLEET_SLUG" ] || [ -z "$STAGE" ]; then
  echo "Usage: resolve-stage.sh --fleet-slug <slug> --stage <staging|production> [--region <aws-region>]" >&2
  exit 2
fi

if [ -z "$AWS_REGION" ]; then
  AWS_REGION="us-east-1"
fi

PARAM_NAME="/bp/${STAGE}/fleets/${FLEET_SLUG}/desired_config"

if aws ssm get-parameter \
  --name "$PARAM_NAME" \
  --query "Parameter.Value" \
  --output text \
  --region "$AWS_REGION" >/dev/null 2>&1; then
  echo "$STAGE"
  exit 0
fi

exit 1
