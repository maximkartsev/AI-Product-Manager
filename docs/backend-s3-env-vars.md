# Backend S3 + CloudFront configuration

This project provisions S3 buckets and a CloudFront distribution in the **data stack** and wires the backend to them in the **compute stack**. The backend should use **IAM task roles** (no static AWS keys) when deployed to AWS.

## Buckets created by CDK (DataStack)

Created in `bp-<stage>-data`:

- **Media bucket**: `bp-media-<account>-<stage>` (user uploads / outputs)
- **Models bucket**: `bp-models-<account>-<stage>` (ComfyUI models / bundles)
- **Logs bucket**: `bp-logs-<account>-<stage>` (application logs / artifacts)
- **Access logs bucket**: `bp-access-logs-<account>-<stage>` (S3 access logs destination)

### Where to find the actual names

In CloudFormation outputs for `bp-<stage>-data`:

- `MediaBucketName`
- `ModelsBucketName`
- `LogsBucketName`
- `CloudFrontDomain` (media CDN domain)

## What URLs the backend returns

In AWS, the backend uses:

- `FILESYSTEM_DISK=s3`
- `AWS_BUCKET=<media-bucket>`
- `AWS_DEFAULT_REGION=<region>`
- `AWS_URL=https://<CloudFrontDomain>`

This ensures `Storage::url(...)` points to the **CloudFront** distribution, not a private S3 URL.

Presigned upload URLs still go directly to S3 and continue to work.
Browser-based uploads to the **models bucket** (Admin → Assets) require S3 CORS rules
to allow the `OPTIONS` preflight before the `PUT` to the presigned URL.

## How to add backend environment variables

### Non-secret env vars

Edit `phpEnvironment` in:

`infrastructure/lib/stacks/compute-stack.ts`

Then redeploy the compute stack.

### Secret env vars

1. Store the value in **AWS Secrets Manager** or **SSM Parameter Store**.
2. Reference it in `phpSecrets` in:

`infrastructure/lib/stacks/compute-stack.ts`

Then redeploy the compute stack.

## ComfyUI buckets

ComfyUI uses dedicated disks:

- Models: `COMFYUI_MODELS_BUCKET`
- Logs: `COMFYUI_LOGS_BUCKET`

If you need different URLs or endpoints for these buckets, set:

- `COMFYUI_MODELS_URL`, `COMFYUI_LOGS_URL`
- `COMFYUI_MODELS_ENDPOINT`, `COMFYUI_LOGS_ENDPOINT`

Otherwise they will default to the main `AWS_*` settings.
