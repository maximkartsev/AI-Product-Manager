param(
  [Parameter(Mandatory = $true)]
  [string] $Region,

  [Parameter(Mandatory = $true)]
  [string] $StagingEnvPath,

  [Parameter(Mandatory = $true)]
  [string] $ProductionEnvPath,

  [Parameter(Mandatory = $true)]
  [string] $AppleP8PathStaging,

  [Parameter(Mandatory = $true)]
  [string] $AppleP8PathProduction,

  [string] $Profile = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$env:AWS_PAGER = ""

function Get-AwsCliArgs {
  $args = @("--region", $Region)
  # Avoid AWS CLI pager hangs in non-interactive runs
  $args += @("--no-cli-pager")
  # Avoid indefinite hangs on network/endpoint issues
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

  # Do not capture output: capturing can hang depending on host/encoding/pager settings.
  # AWS CLI responses here are metadata only (no secret values), so streaming is safe.
  & aws @Arguments
  if ($LASTEXITCODE -ne 0) {
    throw "AWS CLI failed: aws $($Arguments -join ' ')"
  }

  return ""
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
    [string] $Key,
    [Parameter(Mandatory = $true)]
    [string] $Stage
  )

  if (-not $EnvMap.ContainsKey($Key)) {
    throw "[$Stage] Missing required key in env file: $Key"
  }

  $value = [string]$EnvMap[$Key]
  if ([string]::IsNullOrWhiteSpace($value)) {
    throw "[$Stage] Required key is empty in env file: $Key"
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

  Invoke-AwsText (@(
      "secretsmanager", "put-secret-value",
      "--secret-id", $SecretId,
      "--secret-string", $SecretString
    ) + (Get-AwsCliArgs)) | Out-Null
}

function Force-BackendRedeploy {
  param(
    [Parameter(Mandatory = $true)]
    [string] $Stage
  )

  $cluster = "bp-$Stage"
  $service = "bp-$Stage-backend"

  Invoke-AwsText (@(
      "ecs", "update-service",
      "--cluster", $cluster,
      "--service", $service,
      "--force-new-deployment"
    ) + (Get-AwsCliArgs)) | Out-Null
}

function Sync-StageFromEnv {
  param(
    [Parameter(Mandatory = $true)]
    [string] $Stage,
    [Parameter(Mandatory = $true)]
    [hashtable] $EnvMap,
    [Parameter(Mandatory = $true)]
    [string] $AppleP8Path
  )

  if (-not (Test-Path -Path $AppleP8Path -PathType Leaf)) {
    throw "[$Stage] Apple private key file does not exist: $AppleP8Path"
  }

  $fleetEnvKey = if ($Stage -eq "production") { "COMFYUI_FLEET_SECRET_PRODUCTION" } else { "COMFYUI_FLEET_SECRET_STAGING" }
  $fleetSecret = Get-OptionalEnvValue -EnvMap $EnvMap -Key $fleetEnvKey
  $generatedFleetSecret = $false
  if ([string]::IsNullOrWhiteSpace($fleetSecret)) {
    $fleetSecret = New-RandomHexSecret -Bytes 48
    $generatedFleetSecret = $true
  }

  $appKey = Get-RequiredEnvValue -EnvMap $EnvMap -Key "APP_KEY" -Stage $Stage
  $googleClientId = Get-RequiredEnvValue -EnvMap $EnvMap -Key "GOOGLE_CLIENT_ID" -Stage $Stage
  $googleClientSecret = Get-RequiredEnvValue -EnvMap $EnvMap -Key "GOOGLE_CLIENT_SECRET" -Stage $Stage
  $tiktokClientId = Get-RequiredEnvValue -EnvMap $EnvMap -Key "TIKTOK_CLIENT_ID" -Stage $Stage
  $tiktokClientSecret = Get-RequiredEnvValue -EnvMap $EnvMap -Key "TIKTOK_CLIENT_SECRET" -Stage $Stage
  $appleClientId = Get-RequiredEnvValue -EnvMap $EnvMap -Key "APPLE_CLIENT_ID" -Stage $Stage
  $appleKeyId = Get-RequiredEnvValue -EnvMap $EnvMap -Key "APPLE_KEY_ID" -Stage $Stage
  $appleTeamId = Get-RequiredEnvValue -EnvMap $EnvMap -Key "APPLE_TEAM_ID" -Stage $Stage
  $appleClientSecret = Get-OptionalEnvValue -EnvMap $EnvMap -Key "APPLE_CLIENT_SECRET"
  $applePrivateKeyP8B64 = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($AppleP8Path))

  $fleetSecretParamName = "/bp/$Stage/fleet-secret"
  $appKeySecretId = "/bp/$Stage/laravel/app-key"
  $oauthSecretId = "/bp/$Stage/oauth/secrets"

  Write-Host "[$Stage] Writing SSM parameter $fleetSecretParamName"
  Put-SsmStringParameter -Name $fleetSecretParamName -Value $fleetSecret

  Write-Host "[$Stage] Writing Secrets Manager value $appKeySecretId"
  Put-SecretsManagerValue -SecretId $appKeySecretId -SecretString $appKey

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
  Write-Host "[$Stage] Writing Secrets Manager value $oauthSecretId"
  Put-SecretsManagerValue -SecretId $oauthSecretId -SecretString $oauthPayloadJson

  Write-Host "[$Stage] Forcing ECS backend redeploy"
  Force-BackendRedeploy -Stage $Stage

  if ($generatedFleetSecret) {
    Write-Warning "[$Stage] $fleetEnvKey was missing in env file and a new value was generated."
    Write-Warning "[$Stage] A new value was generated and stored in SSM: /bp/$Stage/fleet-secret"
    Write-Warning "[$Stage] Persist this value in your stage env source for repeatable deployments."
  }

  Write-Host "[$Stage] Done."
}

$stagingEnv = Parse-DotEnv -Path $StagingEnvPath
$productionEnv = Parse-DotEnv -Path $ProductionEnvPath

Sync-StageFromEnv -Stage "staging" -EnvMap $stagingEnv -AppleP8Path $AppleP8PathStaging
Sync-StageFromEnv -Stage "production" -EnvMap $productionEnv -AppleP8Path $AppleP8PathProduction

Write-Host "All stages synced successfully."
