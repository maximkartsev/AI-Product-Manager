# Dev GPU Instance for ComfyUI

Launch a single GPU instance with the ComfyUI web UI accessible from the internet for building and testing workflows. Completely separate from the production CDK infrastructure.

## Prerequisites

- **AWS CLI** configured with credentials (`aws configure`)
  - Your AWS identity must be able to use **EC2** (launch/stop instances, create security groups) and read **SSM Parameter Store** (to resolve the AMI from `/bp/ami/fleets/<stage>/<fleet_slug>`).
- **S3 read access** to the models bucket (required only if you plan to apply bundles via the GitHub Action)
- **Production AMI** built via Packer and registered in SSM at `/bp/ami/fleets/<stage>/<fleet_slug>` (or pass `AMI_ID` directly)
- **EC2 key pair** (optional — only needed for SSH)
- **Session Manager**: by default, `launch.sh` will try to create/attach an SSM-enabled instance profile (`bp-comfyui-dev-<stage>`) so the instance appears in Systems Manager. Set `AUTO_INSTANCE_PROFILE=false` to disable, or set `INSTANCE_PROFILE` to override.

## Quick Start

```bash
cd infrastructure/dev-gpu
./launch.sh
# → prints ComfyUI URL, open it in your browser
```

If you get `Permission denied` on `./launch.sh` (common on fresh checkouts), run:

```bash
chmod +x launch.sh shutdown.sh
./launch.sh
```

Or run it explicitly with bash:

```bash
bash ./launch.sh
```

## Apply Bundle to Running Dev Instance (GitHub Action)

If you’re iterating on models/LoRAs/VAEs, you can sync a bundle onto the **running** dev instance without recreating it:

1. Ensure the instance has an **SSM-enabled** instance profile (e.g. `AmazonSSMManagedInstanceCore`).
2. Ensure the instance role has **S3 read** to the models bucket (`bp-models-<account>-<stage>`).
3. Run the workflow `.github/workflows/apply-comfyui-bundle.yml` with:
   - `instance_id`, `fleet_slug`, `bundle_prefix`, `models_bucket`

The workflow will `aws s3 sync` the bundle to `/opt/comfyui` and restart `comfyui.service`.

If you are using the default dev role (`bp-comfyui-dev-<stage>`), `launch.sh` will *try* to attach this automatically (when it can resolve `/bp/<stage>/models/bucket`). If it didn’t, you can attach the policy manually:

```bash
AWS_REGION="us-east-1"
STAGE="staging"
ROLE_NAME="bp-comfyui-dev-${STAGE}"
BUCKET="$(aws ssm get-parameter \
  --region "$AWS_REGION" \
  --name "/bp/${STAGE}/models/bucket" \
  --query 'Parameter.Value' \
  --output text)"

aws iam put-role-policy \
  --role-name "$ROLE_NAME" \
  --policy-name comfyui-models-read \
  --policy-document '{
    "Version": "2012-10-17",
    "Statement": [
      {
        "Effect": "Allow",
        "Action": ["s3:GetObject"],
        "Resource": [
          "arn:aws:s3:::'"$BUCKET"'/assets/*",
          "arn:aws:s3:::'"$BUCKET"'/bundles/*"
        ]
      },
      {
        "Effect": "Allow",
        "Action": ["s3:ListBucket"],
        "Resource": "arn:aws:s3:::'"$BUCKET"'",
        "Condition": {
          "StringLike": {
            "s3:prefix": ["assets/*", "bundles/*"]
          }
        }
      }
    ]
  }'
```

If you see errors like `$'\r': command not found`, your scripts were checked out with Windows (CRLF) line endings. Fix with:

```bash
# Option A (recommended)
dos2unix launch.sh shutdown.sh user-data.sh

# Option B (no extra tools)
sed -i 's/\r$//' launch.sh shutdown.sh user-data.sh
```

When done:

```bash
./shutdown.sh
```

Re-run `./launch.sh` to resume a stopped dev instance.

## Build / Register the AMI

### GitHub Actions (recommended)

Prerequisite (one-time): configure GitHub Actions → AWS auth (OIDC) and set the secrets:

- Create an AWS IAM role that GitHub Actions can assume via OIDC.
- Add the role ARN(s) as repo Actions secrets named `AWS_DEPLOY_ROLE_ARN_STAGING` (and `AWS_DEPLOY_ROLE_ARN_PRODUCTION` if needed).
- Setup details are in [`infrastructure/README.md`](../README.md#github-actions-to-aws-oidc).

1. Go to **Actions → Build GPU AMI**.
2. Run workflow with:
   - `fleet_slug`: e.g. `gpu-default`
   - `stage`: `staging` or `production`
   - `instance_type` (optional override): leave blank to use `/bp/<stage>/fleets/<fleet_slug>/desired_config`
3. The workflow writes the AMI ID to SSM at `/bp/ami/fleets/<stage>/<fleet_slug>`.

If the workflow fails at **Configure AWS credentials** with:

- `Credentials could not be loaded ... Could not load credentials from any providers`

Then your `AWS_DEPLOY_ROLE_ARN_STAGING`/`AWS_DEPLOY_ROLE_ARN_PRODUCTION` secret is missing/empty or the IAM role trust policy doesn’t allow this repo/branch. Re-check the OIDC setup in [`infrastructure/README.md`](../README.md#github-actions-to-aws-oidc).

### Local Packer

```bash
cd infrastructure/packer
packer init .
packer build \
  -var "fleet_slug=gpu-default" \
  -var "instance_type=g4dn.xlarge" \
  -var "aws_region=us-east-1" \
  .
```

Then write the AMI ID to SSM:

```bash
aws ssm put-parameter \
  --name "/bp/ami/fleets/staging/gpu-default" \
  --value "ami-xxxxxxxx" \
  --data-type "aws:ec2:image" \
  --type String \
  --overwrite
```

## Create an EC2 Key Pair (optional, SSH)

```bash
aws ec2 create-key-pair \
  --key-name comfyui-dev \
  --query 'KeyMaterial' \
  --output text > ~/.ssh/comfyui-dev.pem

chmod 600 ~/.ssh/comfyui-dev.pem
```

Then launch with:

```bash
KEY_NAME=comfyui-dev ./launch.sh
```

SSH:

```bash
ssh -i ~/.ssh/comfyui-dev.pem ubuntu@<public-ip>
```

## Enable Session Manager (optional)

By default, `launch.sh` will create/attach a profile automatically (see `AUTO_INSTANCE_PROFILE` below).

To use a specific existing instance profile:

```bash
INSTANCE_PROFILE=MyExistingInstanceProfile ./launch.sh
```

## Troubleshooting: instance not in SSM Managed nodes

1. **Region check**: make sure the AWS Console region matches the instance region.
2. **Check instance profile**:
   ```bash
   aws ec2 describe-instances --region us-east-1 --instance-ids i-xxxx \
     --query 'Reservations[0].Instances[0].IamInstanceProfile.Arn' --output text
   ```
   - If `None`, attach an instance profile with `AmazonSSMManagedInstanceCore`.
3. **Check SSM agent on the instance**:
   ```bash
   sudo systemctl status amazon-ssm-agent --no-pager || true
   sudo systemctl status snap.amazon-ssm-agent.amazon-ssm-agent.service --no-pager || true
   ```

## Configuration

All settings are via environment variables:

| Variable | Default | Description |
|---|---|---|
| `AWS_REGION` | `us-east-1` | AWS region |
| `STAGE` | `staging` | SSM stage for fleet AMI lookup |
| `FLEET_SLUG` | `gpu-default` | Fleet slug for AMI lookup |
| `AMI_SSM_PARAM` | *(none)* | Override SSM parameter path for AMI lookup |
| `INSTANCE_TYPE` | `g4dn.xlarge` | EC2 instance type (must have GPU) |
| `WORKFLOW_SLUG` | `image-to-video` | Legacy fallback if `AMI_SSM_PARAM` is set to `/bp/ami/<workflow-slug>` |
| `AMI_ID` | *(from SSM)* | Override AMI ID instead of SSM lookup |
| `KEY_NAME` | *(none)* | EC2 key pair name for SSH access |
| `INSTANCE_PROFILE` | *(none)* | IAM instance profile (Name or ARN) for SSM Session Manager |
| `AUTO_INSTANCE_PROFILE` | `true` | If `true` and `INSTANCE_PROFILE` is empty, attempt to create/attach `bp-comfyui-dev-<stage>` with `AmazonSSMManagedInstanceCore` (and S3 read to the models bucket if resolvable) |
| `MODELS_BUCKET` | *(none)* | Optional: override models bucket name for attaching S3 read permissions to the auto-created dev role |
| `AUTO_SHUTDOWN_HOURS` | `4` | Hours before auto-stop |
| `VOLUME_SIZE` | `100` | Root volume size in GB |

Example with overrides:

```bash
INSTANCE_TYPE=g5.xlarge KEY_NAME=my-key AUTO_SHUTDOWN_HOURS=8 ./launch.sh
```

## How It Works

1. **Reuses the production AMI** — no separate Packer build needed
2. **User-data reconfigures on boot**: disables the worker daemon, changes ComfyUI to listen on `0.0.0.0`
3. **Security group** restricts port 8188 (and SSH if key pair set) to your current public IP only
4. **Auto-shutdown** stops the instance after the configured time limit (default 4h)

## Loading Models

SCP (if you set `KEY_NAME`):

```bash
scp -i ~/.ssh/my-key.pem my-model.safetensors ubuntu@<ip>:/opt/comfyui/models/checkpoints/
```

Details:
- The `-i` flag points to your **private key** file (downloaded when you created the EC2 key pair).
- `<ip>` is the **public IP** printed by `./launch.sh`.
- `checkpoints/` is the ComfyUI folder for main checkpoint models.

Before SCP (required by SSH):

```bash
chmod 600 ~/.ssh/my-key.pem
```

Other common model folders:

```bash
# LoRA
scp -i ~/.ssh/my-key.pem my-lora.safetensors ubuntu@<ip>:/opt/comfyui/models/loras/

# VAE
scp -i ~/.ssh/my-key.pem my-vae.safetensors ubuntu@<ip>:/opt/comfyui/models/vae/

# ControlNet
scp -i ~/.ssh/my-key.pem my-control.safetensors ubuntu@<ip>:/opt/comfyui/models/controlnet/
```

Verify and reload:

```bash
ssh -i ~/.ssh/my-key.pem ubuntu@<ip>
ls -lh /opt/comfyui/models/checkpoints/
sudo systemctl restart comfyui
```

S3 sync (from the instance):

```bash
ssh ubuntu@<ip>
aws s3 sync s3://my-bucket/models/ /opt/comfyui/models/
sudo systemctl restart comfyui
```

## Cost Estimate

| Instance | GPU | On-Demand $/hr |
|---|---|---|
| g4dn.xlarge | T4 16GB | ~$0.53 |
| g5.xlarge | A10G 24GB | ~$1.01 |
| g6.xlarge | L4 24GB | ~$0.98 |

With default 4h auto-shutdown: **~$2.12** max per session (g4dn.xlarge).

Stopped instances still incur **EBS gp3 storage** charges.

Approximate gp3 storage cost (us-east-1):
- $0.08 per GB-month
- Example (default 100 GB): ~$8/month (~$0.26/day)
- If you provision >3000 IOPS or >125 MB/s throughput, extra charges apply

## Troubleshooting

**ComfyUI URL not loading after launch**

The instance needs 1-2 minutes after reaching "running" state for user-data to execute and ComfyUI to start. SSH in and check:

```bash
# Check user-data execution log
cat /var/log/dev-gpu-setup.log

# Check ComfyUI service status
sudo systemctl status comfyui

# Check ComfyUI logs
sudo journalctl -u comfyui -f
```

**ERROR: Could not resolve AMI**

`./launch.sh` resolves the AMI ID from SSM at `/bp/ami/fleets/<stage>/<fleet_slug>` (defaults: `staging`, `gpu-default`).

- If the parameter doesn’t exist, either create it (see “Build / Register the AMI”) or pass `AMI_ID=ami-...` when launching.
- Alternatively, pass `AMI_SSM_PARAM=/bp/ami/<workflow-slug>` to use legacy per-workflow AMIs.
- If you see `AccessDeniedException` for `ssm:GetParameter`, your AWS identity lacks SSM read permissions. You can fix it by attaching `AmazonSSMReadOnlyAccess`, or by adding this minimal inline policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadGpuAmiFromSsm",
      "Effect": "Allow",
      "Action": ["ssm:GetParameter"],
      "Resource": [
        "arn:aws:ssm:us-east-1:<ACCOUNT_ID>:parameter/bp/ami/*",
        "arn:aws:ssm:us-east-1:<ACCOUNT_ID>:parameter/bp/ami/fleets/*"
      ]
    }
  ]
}
```

**UnauthorizedOperation / AccessDenied for EC2**

If you see errors like `not authorized to perform: ec2:DescribeVpcs` (or `RunInstances`, `CreateSecurityGroup`, etc.), your AWS identity lacks EC2 permissions required by `launch.sh` / `shutdown.sh`.

Quick fix (broad): attach the AWS managed policy **`AmazonEC2FullAccess`**.

Minimal inline policy (covers what the dev-gpu scripts use):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "DevGpuEc2",
      "Effect": "Allow",
      "Action": [
        "ec2:DescribeVpcs",
        "ec2:DescribeSubnets",
        "ec2:DescribeInstances",
        "ec2:DescribeSecurityGroups",
        "ec2:RunInstances",
        "ec2:StartInstances",
        "ec2:StopInstances",
        "ec2:TerminateInstances",
        "ec2:CreateSecurityGroup",
        "ec2:DeleteSecurityGroup",
        "ec2:AuthorizeSecurityGroupIngress",
        "ec2:RevokeSecurityGroupIngress",
        "ec2:CreateTags"
      ],
      "Resource": "*"
    }
  ]
}
```

**"Connection refused" or timeout**

Your public IP may have changed since launch. Re-run `./launch.sh` — it will update the security group with your new IP (and warn about the existing instance).

**Stop vs terminate**

- `./shutdown.sh` stops by default (keeps the instance for later resume).
- `./shutdown.sh --terminate` terminates the instance.
- Use `./shutdown.sh --terminate --cleanup` to also delete the managed security group.

**Worker service is running**

User-data should disable it. Manually stop:

```bash
sudo systemctl disable --now comfyui-worker.service
```

**Cancel auto-shutdown**

```bash
sudo shutdown -c
```

**Check remaining time before shutdown**

```bash
sudo shutdown --show
```
