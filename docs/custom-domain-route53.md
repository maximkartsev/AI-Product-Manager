# Custom Domain + Route 53 Migration Runbook

This document explains how to use your own domain for the frontend in this repo and how to move a domain from another registrar/DNS provider (for example GoDaddy) to Route 53. It is written as a step-by-step checklist with copy/paste commands.

## Architecture context (this repo)

- The ALB serves **all frontend paths** (`/*`) to Next.js and **API paths** (`/api/*`, `/sanctum/*`, `/up`) to the backend.
- HTTPS is enabled **only** when a certificate ARN is provided.
- The app base URL for the frontend/backend is set to `https://<domainName>` when HTTPS is enabled.

Relevant code:
- `[infrastructure/bin/app.ts](../infrastructure/bin/app.ts)` (CDK context keys `domainName` and `certificateArn`)
- `[infrastructure/lib/stacks/compute-stack.ts](../infrastructure/lib/stacks/compute-stack.ts)` (ALB listeners + HTTP to HTTPS redirect)

## Decide your hostname

Pick the exact hostname you want users to visit:

- **Recommended:** `app.example.com`
- Avoid apex/root unless you know your DNS provider supports ALIAS/ANAME records.

You will use this exact hostname for the ACM certificate and DNS record.

## Prerequisites

- AWS CLI configured (`aws sts get-caller-identity`)
- Domain ownership with access to DNS at your current registrar/provider
- Decide your AWS region (this repo defaults to `us-east-1`)

**Important:** For an ALB certificate, the ACM certificate must be in the **same region** as the ALB.

## If you can't transfer the domain from your registrar (for now)

You **do not need** to transfer the domain registration to Route 53 to use Route 53.

- **Registrar**: where you pay for and renew the domain (GoDaddy, Namecheap, etc.)
- **DNS hosting**: where your DNS records live (A/AAAA/CNAME/MX/TXT/...)

If domain transfer is blocked (for example the common 60-day transfer lock), you can still move **DNS hosting** to Route 53 by:

1. Creating a Route 53 **public hosted zone**
2. Copying your existing DNS records into that hosted zone
3. Updating **nameservers at your current registrar** to the Route 53 nameservers (DNS delegation)

After nameservers are updated, Route 53 becomes authoritative and your Route 53 records start taking effect.

If you cannot change nameservers either, you can still point traffic to the ALB by creating the needed DNS records at your current DNS provider (and postpone Route 53 entirely).

## Option A (recommended): Keep registrar at GoDaddy, move DNS to Route 53

This is the fastest and safest route. You only change DNS nameservers (no transfer lock delays), and the registrar stays at GoDaddy.

### Step 1: Create a public hosted zone in Route 53

1. AWS Console -> Route 53 -> Hosted zones -> Create hosted zone
2. Domain name: `example.com` (root domain, not `app.example.com`)
3. Type: Public hosted zone

Copy the **Route 53 nameservers (NS)** from the hosted zone. You'll use them at your registrar.

Optional (recommended): 24-48 hours before cutover, lower DNS TTLs on records you care about (for example, 300 seconds) to speed up propagation.

### Step 2: Migrate existing DNS records

Before you change nameservers, replicate your existing DNS records into Route 53.

From GoDaddy (or your current DNS provider), export or copy:
- A / AAAA records
- CNAME records
- MX records
- TXT records (SPF / DKIM / DMARC)

Create the same records inside the Route 53 hosted zone. This prevents email/web outages during DNS cutover.
When you later change nameservers, the DNS records in GoDaddy are **no longer used**.

### Step 3: Create the ACM certificate (DNS validation)

Request a certificate in the same region as your ALB (usually `us-east-1`):

```bash
aws acm request-certificate \
  --domain-name app.example.com \
  --validation-method DNS \
  --region us-east-1
```

Then create the **CNAME validation record** in Route 53:
- ACM Console -> Certificate -> "Create record in Route 53"
- Or manually copy the validation CNAME into the hosted zone.

Wait for the certificate status to become **Issued**.

### Step 4: Point your hostname to the ALB

Create a Route 53 record in the hosted zone:

- If you are using a subdomain like `app.example.com`:
  - Record name: `app`
  - Record type: **A**
  - Alias: **Yes**
  - Alias target: **ALB DNS name** from the `bp-<stage>-compute` stack output `AlbDns`
- If you are using the apex/root like `example.com` (no subdomain):
  - Record name: (leave blank) or `example.com` (depending on the UI)
  - Record type: **A**
  - Alias: **Yes**
  - Alias target: **ALB DNS name** from the `bp-<stage>-compute` stack output `AlbDns`

Route 53 will automatically create the ALIAS to the ALB.

Optional:
- Create `www.example.com` as a **CNAME** to `example.com` (or a second alias record to the ALB).
- Create an **AAAA** alias only if you have enabled IPv6/dualstack on the ALB. Otherwise AAAA lookups may return no answer even if the record exists.

### Step 5: Update nameservers at GoDaddy

In GoDaddy:
1. Go to Domain settings -> Nameservers
2. Choose **Custom**
3. Replace existing nameservers with the Route 53 NS values

DNS propagation can take minutes to hours (up to 48h in worst cases).

Verify delegation after you change nameservers:

```bash
nslookup -type=ns example.com
```

You should see the Route 53 nameservers (the same ones shown in your hosted zone NS record).

### Step 6: Deploy with domain + certificate

Deploy compute with the custom domain + cert:

```bash
cd infrastructure
npx cdk deploy bp-staging-compute \
  --context stage=staging \
  --context domainName=app.example.com \
  --context certificateArn=arn:aws:acm:us-east-1:123456789012:certificate/xxxx \
  --require-approval never
```

Repeat for production with `--context stage=production`.

## Option B: Transfer registration to Route 53 (full registrar move)

This moves the domain registration away from GoDaddy into Route 53 Domains.

### Step 1: Verify transfer eligibility

Typical constraints (varies by TLD):
- Domain must be **older than 60 days** (since registration or last transfer).
- Domain must be **unlocked** (no "transfer lock").
- You need the **authorization/EPP code**.
- Registrant email must be valid and accessible.

### Step 2: Prepare the domain at GoDaddy

In GoDaddy:
1. Unlock the domain
2. Disable domain privacy (or ensure you can receive transfer emails)
3. Request the **EPP/authorization code**

### Step 3: Start transfer in Route 53

AWS Console -> Route 53 -> Registered domains -> Transfer domain:
- Enter your domain
- Paste EPP/authorization code
- Confirm contact details

Expect an ICANN confirmation email and a transfer window of up to 5-7 days.

### Step 4: After transfer completes

Once Route 53 is the registrar, create/update the **public hosted zone** and **NS records** as in Option A.
Note: transferring the registration does **not** automatically create or migrate DNS records.

## Verification checklist

### 1) DNS resolution

```bash
nslookup -type=ns example.com
nslookup app.example.com
```

- `nslookup -type=ns example.com` should show Route 53 nameservers.
- `nslookup app.example.com` should resolve to your ALB DNS name or IPs.

### 2) ACM certificate

In AWS Console -> ACM:
- Status is **Issued**
- Certificate is in the ALB region

### 3) ALB listener

From the `bp-<stage>-compute` stack:
- Listener 443 exists (HTTPS)
- HTTP (80) redirects to HTTPS

### 4) App availability

Open `https://app.example.com`:
- Frontend should load
- API routes (`https://app.example.com/api/...`) should respond

## Troubleshooting

### ACM stuck in "Pending validation"
- Confirm the CNAME validation record exists in the hosted zone **that serves your domain**.
- If you have multiple hosted zones, ensure the CNAME is in the correct one.
- Check for restrictive **CAA records** at the domain apex.

### Certificate in wrong region
- ALB only accepts certs from its region. Request a new ACM cert in the same region as the ALB.

### Apex/root domain issues
- If you try to use `example.com` (apex), you need a DNS provider that supports **Alias/ANAME**.
- Route 53 supports alias at apex; many external registrars do not.
- Prefer `app.example.com`.

### "Not Found" or 404 from ALB
- Default listener action is a fixed 404. Ensure the host resolves to the ALB and the target groups are healthy.

### Redirect loops
- If you terminate TLS elsewhere (e.g., CloudFront) and also force HTTPS at the ALB, you can create loops.
- Use a single TLS termination point.

## Rollback plan

If something goes wrong:

1. Revert nameservers to the previous DNS provider.
2. Restore the original DNS records there.
3. (Optional) Remove `domainName` / `certificateArn` context to fall back to ALB HTTP.

## Common commands (copy/paste)

Get the ALB DNS output:

```bash
aws cloudformation describe-stacks \
  --stack-name bp-staging-compute \
  --query "Stacks[0].Outputs[?OutputKey=='AlbDns'].OutputValue" \
  --output text
```

Deploy with domain + cert:

```bash
cd infrastructure
npx cdk deploy bp-staging-compute \
  --context stage=staging \
  --context domainName=app.example.com \
  --context certificateArn=arn:aws:acm:us-east-1:123456789012:certificate/xxxx \
  --require-approval never
```
