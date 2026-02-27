param(
  [Parameter(Mandatory = $true)]
  [string] $Region,

  [Parameter(Mandatory = $true)]
  [string] $EnvPath,

  [Parameter(Mandatory = $true)]
  [string] $AppleP8Path,

  [string] $Profile = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$env:AWS_PAGER = ""

function Get-AwsCliArgs {
  $args = @("--region", $Region)
  $args += @("--no-cli-pager")
  $args += @("--cli-connect-timeout", "10", "--cli-read-timeout", "60")
  if (-not [string]::IsNullOrWhiteSpace($Profile)) {
    $args += @("--profile", $Profile)
  }
  return $args
}

function Invoke-AwsText {
  param(
    [Parameter(Mandatory = $true)]
    [string[]] $Arguments
  )

  function Redact-AwsArgs([string[]] $ArgsIn) {
    $out = New-Object System.Collections.Generic.List[string]
    for ($i = 0; $i -lt $ArgsIn.Count; $i++) {
      $tok = $ArgsIn[$i]
      $out.Add($tok) | Out-Null
      if ($tok -in @('--secret-string','--value')) {
        if ($i + 1 -lt $ArgsIn.Count) {
          $i++
          $out.Add('<REDACTED>') | Out-Null
        }
      }
    }
    return $out.ToArray()
  }

  & aws @Arguments
  if ($LASTEXITCODE -ne 0) {
    $safe = (Redact-AwsArgs $Arguments) -join ' '
    throw "AWS CLI failed: aws $safe"
  }

  return ""
}

function Invoke-AwsOutput {
  param(
    [Parameter(Mandatory = $true)]
    [string[]] $Arguments
  )

  function Redact-AwsArgs([string[]] $ArgsIn) {
    $out = New-Object System.Collections.Generic.List[string]
    for ($i = 0; $i -lt $ArgsIn.Count; $i++) {
      $tok = $ArgsIn[$i]
      $out.Add($tok) | Out-Null
      if ($tok -in @('--secret-string','--value')) {
        if ($i + 1 -lt $ArgsIn.Count) {
          $i++
          $out.Add('<REDACTED>') | Out-Null
        }
      }
    }
    return $out.ToArray()
  }

  $output = & aws @Arguments
  if ($LASTEXITCODE -ne 0) {
    $safe = (Redact-AwsArgs $Arguments) -join ' '
    throw "AWS CLI failed: aws $safe"
  }

  return [string]$output
}

function Parse-DotEnv {
  param(
    [Parameter(Mandatory = $true)]
    [string] $Path
  )

  if (-not (Test-Path -Path $Path -PathType Leaf)) {
    throw "Missing env file: $Path"
  }

  $values = @{}
  foreach ($rawLine in Get-Content -Path $Path) {
    $line = $rawLine.Trim()
    if ($line.Length -eq 0 -or $line.StartsWith("#")) {
      continue
    }

    if ($line.StartsWith("export ")) {
      $line = $line.Substring(7).Trim()
    }

    $separator = $line.IndexOf("=")
    if ($separator -le 0) {
      continue
    }

    $key = $line.Substring(0, $separator).Trim()
    $value = $line.Substring($separator + 1)

    if (
      ($value.StartsWith('"') -and $value.EndsWith('"') -and $value.Length -ge 2) -or
      ($value.StartsWith("'") -and $value.EndsWith("'") -and $value.Length -ge 2)
    ) {
      $value = $value.Substring(1, $value.Length - 2)
    }

    $values[$key] = $value
  }

  return $values
}

function Get-RequiredEnvValue {
  param(
    [Parameter(Mandatory = $true)]
    [hashtable] $EnvMap,
    [Parameter(Mandatory = $true)]
    [string] $Key
  )

  if (-not $EnvMap.ContainsKey($Key)) {
    throw "Missing required key in env file: $Key"
  }

  $value = [string]$EnvMap[$Key]
  if ([string]::IsNullOrWhiteSpace($value)) {
    throw "Required key is empty in env file: $Key"
  }

  return $value
}

function Get-OptionalEnvValue {
  param(
    [Parameter(Mandatory = $true)]
    [hashtable] $EnvMap,
    [Parameter(Mandatory = $true)]
    [string] $Key
  )

  if (-not $EnvMap.ContainsKey($Key)) {
    return ""
  }

  return [string]$EnvMap[$Key]
}

function New-RandomHexSecret {
  param(
    [int] $Bytes = 32
  )

  $randomBytes = New-Object byte[] $Bytes
  [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($randomBytes)
  return ([System.BitConverter]::ToString($randomBytes)).Replace("-", "").ToLowerInvariant()
}

function Put-SsmStringParameter {
  param(
    [Parameter(Mandatory = $true)]
    [string] $Name,
    [Parameter(Mandatory = $true)]
    [string] $Value
  )

  Invoke-AwsText (@(
      "ssm", "put-parameter",
      "--name", $Name,
      "--value", $Value,
      "--type", "String",
      "--overwrite"
    ) + (Get-AwsCliArgs)) | Out-Null
}

function Put-SecretsManagerValue {
  param(
    [Parameter(Mandatory = $true)]
    [string] $SecretId,
    [Parameter(Mandatory = $true)]
    [string] $SecretString
  )

  $tempFile = [System.IO.Path]::GetTempFileName()
  try {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($tempFile, $SecretString, $utf8NoBom)

    $normalizedPath = $tempFile -replace '\\', '/'
    $secretStringFileRef = "file://$normalizedPath"

    Invoke-AwsText (@(
        "secretsmanager", "put-secret-value",
        "--secret-id", $SecretId,
        "--secret-string", $secretStringFileRef
      ) + (Get-AwsCliArgs)) | Out-Null
  }
  finally {
    if (Test-Path -Path $tempFile -PathType Leaf) {
      Remove-Item -Path $tempFile -Force -ErrorAction SilentlyContinue
    }
  }
}

function Force-BackendRedeploy {
  $cluster = "bp"
  $service = "bp-backend"

  Invoke-AwsText (@(
      "ecs", "update-service",
      "--cluster", $cluster,
      "--service", $service,
      "--force-new-deployment"
    ) + (Get-AwsCliArgs)) | Out-Null
}

if (-not (Test-Path -Path $AppleP8Path -PathType Leaf)) {
  throw "Apple private key file does not exist: $AppleP8Path"
}

$envMap = Parse-DotEnv -Path $EnvPath

$appKey = Get-RequiredEnvValue -EnvMap $envMap -Key "APP_KEY"
$googleClientId = Get-RequiredEnvValue -EnvMap $envMap -Key "GOOGLE_CLIENT_ID"
$googleClientSecret = Get-RequiredEnvValue -EnvMap $envMap -Key "GOOGLE_CLIENT_SECRET"
$tiktokClientId = Get-RequiredEnvValue -EnvMap $envMap -Key "TIKTOK_CLIENT_ID"
$tiktokClientSecret = Get-RequiredEnvValue -EnvMap $envMap -Key "TIKTOK_CLIENT_SECRET"
$appleClientId = Get-RequiredEnvValue -EnvMap $envMap -Key "APPLE_CLIENT_ID"
$appleKeyId = Get-RequiredEnvValue -EnvMap $envMap -Key "APPLE_KEY_ID"
$appleTeamId = Get-RequiredEnvValue -EnvMap $envMap -Key "APPLE_TEAM_ID"
$appleClientSecret = Get-OptionalEnvValue -EnvMap $envMap -Key "APPLE_CLIENT_SECRET"
$applePrivateKeyP8B64 = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($AppleP8Path))

$fleetSecretStaging = Get-OptionalEnvValue -EnvMap $envMap -Key "COMFYUI_FLEET_SECRET_STAGING"
$fleetSecretProduction = Get-OptionalEnvValue -EnvMap $envMap -Key "COMFYUI_FLEET_SECRET_PRODUCTION"
$generatedStaging = $false
$generatedProduction = $false
if ([string]::IsNullOrWhiteSpace($fleetSecretStaging)) {
  $fleetSecretStaging = New-RandomHexSecret -Bytes 48
  $generatedStaging = $true
}
if ([string]::IsNullOrWhiteSpace($fleetSecretProduction)) {
  $fleetSecretProduction = New-RandomHexSecret -Bytes 48
  $generatedProduction = $true
}

$oauthPayload = [ordered]@{
  google_client_id = $googleClientId
  google_client_secret = $googleClientSecret
  tiktok_client_id = $tiktokClientId
  tiktok_client_secret = $tiktokClientSecret
  apple_client_id = $appleClientId
  apple_client_secret = $appleClientSecret
  apple_key_id = $appleKeyId
  apple_team_id = $appleTeamId
  apple_private_key_p8_b64 = $applePrivateKeyP8B64
}
$oauthPayloadJson = $oauthPayload | ConvertTo-Json -Depth 10 -Compress

Write-Host "[system] Writing SSM parameter /bp/fleets/staging/fleet-secret"
Put-SsmStringParameter -Name "/bp/fleets/staging/fleet-secret" -Value $fleetSecretStaging

Write-Host "[system] Writing SSM parameter /bp/fleets/production/fleet-secret"
Put-SsmStringParameter -Name "/bp/fleets/production/fleet-secret" -Value $fleetSecretProduction

Write-Host "[system] Writing Secrets Manager value /bp/laravel/app-key"
Put-SecretsManagerValue -SecretId "/bp/laravel/app-key" -SecretString $appKey

Write-Host "[system] Writing Secrets Manager value /bp/oauth/secrets"
Put-SecretsManagerValue -SecretId "/bp/oauth/secrets" -SecretString $oauthPayloadJson

# Validate OAuth secret shape immediately after write to catch malformed payloads.
$storedOauthJson = Invoke-AwsOutput (@(
    "secretsmanager", "get-secret-value",
    "--secret-id", "/bp/oauth/secrets",
    "--query", "SecretString",
    "--output", "text"
  ) + (Get-AwsCliArgs))

if ([string]::IsNullOrWhiteSpace($storedOauthJson)) {
  throw "Stored /bp/oauth/secrets SecretString is empty."
}

try {
  $storedOauth = $storedOauthJson | ConvertFrom-Json
}
catch {
  throw "Stored /bp/oauth/secrets is not valid JSON. Re-run sync after fixing env values."
}

$requiredOauthKeys = @(
  "google_client_id",
  "google_client_secret",
  "tiktok_client_id",
  "tiktok_client_secret",
  "apple_client_id",
  "apple_client_secret",
  "apple_key_id",
  "apple_team_id",
  "apple_private_key_p8_b64"
)

$storedOauthKeys = @($storedOauth.PSObject.Properties.Name)
$missingOauthKeys = @($requiredOauthKeys | Where-Object { $_ -notin $storedOauthKeys })
if ($missingOauthKeys.Count -gt 0) {
  throw ("Stored /bp/oauth/secrets is missing required keys: " + ($missingOauthKeys -join ", "))
}

Write-Host ("[system] OAuth secret validated: google_client_id_len={0}, google_client_secret_len={1}, apple_private_key_p8_b64_len={2}" -f `
  ([string]$storedOauth.google_client_id).Length, `
  ([string]$storedOauth.google_client_secret).Length, `
  ([string]$storedOauth.apple_private_key_p8_b64).Length)

Write-Host "[system] Forcing ECS backend redeploy"
Force-BackendRedeploy

if ($generatedStaging) {
  Write-Warning "[system] COMFYUI_FLEET_SECRET_STAGING was missing in env file and a new value was generated."
  Write-Warning "[system] A new value was stored in SSM: /bp/fleets/staging/fleet-secret"
}
if ($generatedProduction) {
  Write-Warning "[system] COMFYUI_FLEET_SECRET_PRODUCTION was missing in env file and a new value was generated."
  Write-Warning "[system] A new value was stored in SSM: /bp/fleets/production/fleet-secret"
}
if ($generatedStaging -or $generatedProduction) {
  Write-Warning "[system] Persist generated fleet secrets in your env source for repeatable deployments."
}

Write-Host "Single-system secret sync completed successfully."
