[CmdletBinding()]
param(
    [ValidateSet('smoke', 'steady', 'recovery')]
    [string] $Mode = 'smoke',
    [string] $ApacheImage = 'ghcr.io/gigaionllc/rustdesk-api-server:1.0.1@sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2',
    [string] $NginxImage = 'rustdesk-api:nginx-candidate',
    [ValidateSet('always', 'missing', 'never')]
    [string] $NginxPullPolicy = 'never',
    [int] $DeviceCount = 0,
    [double] $ActiveFraction = -1,
    [string] $WarmupDuration = '',
    [string] $TestDuration = '',
    [int] $Trials = 0,
    [int] $PreAllocatedVus = 0,
    [int] $MaxVus = 0,
    [ValidateRange(0, 2147483647)]
    [int] $OrderSeed = 20260718,
    [string] $ResultsDirectory = '',
    [string] $AppCpus = '2.0',
    [string] $AppMemory = '1024m',
    [string] $DatabaseCpus = '2.0',
    [string] $DatabaseMemory = '2048m',
    [string] $LoadGeneratorCpus = '',
    [string] $LoadGeneratorMemory = '',
    [string] $LoadGeneratorUser = '12345:12345'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptDirectory '..\..'))
$composeFile = Join-Path $repositoryRoot 'docker\compose.performance.yml'
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
if ([string]::IsNullOrWhiteSpace($ResultsDirectory)) {
    $ResultsDirectory = Join-Path $scriptDirectory "results\$timestamp-$Mode"
}
New-Item -ItemType Directory -Force -Path $ResultsDirectory | Out-Null
$ResultsDirectory = (Resolve-Path -LiteralPath $ResultsDirectory).Path
if (Get-ChildItem -LiteralPath $ResultsDirectory -Filter '*.summary.json' -File -ErrorAction SilentlyContinue) {
    throw "ResultsDirectory already contains summary files: $ResultsDirectory"
}

$defaults = switch ($Mode) {
    'smoke' {
        @{ Devices = 30; Active = 0.2; Warmup = '5s'; Duration = '20s'; Trials = 1; Enforce = 'false'; P95 = 250; P99 = 750; PreVus = 20; MaxVus = 100; K6Cpus = '2.0'; K6Memory = '1024m' }
    }
    'steady' {
        @{ Devices = 15000; Active = 0.2; Warmup = '2m'; Duration = '30m'; Trials = 3; Enforce = 'true'; P95 = 250; P99 = 750; PreVus = 1024; MaxVus = 4096; K6Cpus = '4.0'; K6Memory = '4096m' }
    }
    'recovery' {
        @{ Devices = 10000; Active = 1.0; Warmup = '15s'; Duration = '60s'; Trials = 1; Enforce = 'true'; P95 = 2000; P99 = 2000; PreVus = 1024; MaxVus = 4096; K6Cpus = '4.0'; K6Memory = '4096m' }
    }
}

if ($DeviceCount -eq 0) { $DeviceCount = $defaults.Devices }
if ($ActiveFraction -lt 0) { $ActiveFraction = $defaults.Active }
if ([string]::IsNullOrWhiteSpace($WarmupDuration)) { $WarmupDuration = $defaults.Warmup }
if ([string]::IsNullOrWhiteSpace($TestDuration)) { $TestDuration = $defaults.Duration }
if ($Trials -eq 0) { $Trials = $defaults.Trials }
if ($PreAllocatedVus -eq 0) { $PreAllocatedVus = $defaults.PreVus }
if ($MaxVus -eq 0) { $MaxVus = $defaults.MaxVus }
if ([string]::IsNullOrWhiteSpace($LoadGeneratorCpus)) { $LoadGeneratorCpus = $defaults.K6Cpus }
if ([string]::IsNullOrWhiteSpace($LoadGeneratorMemory)) { $LoadGeneratorMemory = $defaults.K6Memory }

if ($DeviceCount -lt 1 -or $DeviceCount -gt 50000) { throw 'DeviceCount must be between 1 and 50000.' }
if ($ActiveFraction -lt 0 -or $ActiveFraction -gt 1) { throw 'ActiveFraction must be between 0 and 1.' }
if ($Trials -lt 1 -or $Trials -gt 100) { throw 'Trials must be between 1 and 100.' }
if ($PreAllocatedVus -lt 1 -or $PreAllocatedVus -gt 20000) { throw 'PreAllocatedVus must be between 1 and 20000.' }
if ($MaxVus -lt $PreAllocatedVus -or $MaxVus -gt 50000) { throw 'MaxVus must be at least PreAllocatedVus and no more than 50000.' }

$projectName = "rdapi-perf-$PID".ToLowerInvariant()
$composeArguments = @('compose', '-p', $projectName, '-f', $composeFile)
$runFailed = $false

function Invoke-Docker {
    param([Parameter(Mandatory)][string[]] $Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($Arguments -join ' ') failed with exit code $LASTEXITCODE"
    }
}

function Stop-PerformanceStack {
    $previousPreference = $ErrorActionPreference
    try {
        # Docker Compose writes normal lifecycle progress to stderr. Windows PowerShell turns
        # that stream into ErrorRecord objects, so cleanup must judge the native exit code only.
        $ErrorActionPreference = 'Continue'
        & docker @composeArguments down --volumes --remove-orphans 2>&1 | Out-Null
    } finally {
        $ErrorActionPreference = $previousPreference
    }
}

$env:PERF_MODE = $Mode
$env:PERF_DEVICE_COUNT = [string] $DeviceCount
$env:PERF_ACTIVE_FRACTION = [string]::Format([Globalization.CultureInfo]::InvariantCulture, '{0}', $ActiveFraction)
$env:PERF_WARMUP_DURATION = $WarmupDuration
$env:PERF_TEST_DURATION = $TestDuration
$env:PERF_ENFORCE_GATE = $defaults.Enforce
$env:PERF_P95_LIMIT_MS = [string] $defaults.P95
$env:PERF_P99_LIMIT_MS = [string] $defaults.P99
$env:PERF_PREALLOCATED_VUS = [string] $PreAllocatedVus
$env:PERF_MAX_VUS = [string] $MaxVus
$env:PERF_TRIALS = [string] $Trials
$env:PERF_ORDER_SEED = [string] $OrderSeed
$env:PERF_RESULTS_DIR = $ResultsDirectory
$env:PERF_APP_CPUS = $AppCpus
$env:PERF_APP_MEMORY = $AppMemory
$env:PERF_DB_CPUS = $DatabaseCpus
$env:PERF_DB_MEMORY = $DatabaseMemory
$env:PERF_K6_CPUS = $LoadGeneratorCpus
$env:PERF_K6_MEMORY = $LoadGeneratorMemory
$env:PERF_K6_USER = $LoadGeneratorUser
$env:PERF_IMAGE = $ApacheImage
$env:PERF_PULL_POLICY = 'missing'

try {
    Invoke-Docker ($composeArguments + @('--profile', 'load', 'config', '--quiet'))
    $orderRandom = New-Object -TypeName System.Random -ArgumentList $OrderSeed

    for ($trial = 1; $trial -le $Trials; $trial++) {
        $targets = if ($orderRandom.Next(0, 2) -eq 0) {
            @(
                @{ Name = 'apache'; Image = $ApacheImage; Pull = 'missing' },
                @{ Name = 'nginx'; Image = $NginxImage; Pull = $NginxPullPolicy }
            )
        } else {
            @(
                @{ Name = 'nginx'; Image = $NginxImage; Pull = $NginxPullPolicy },
                @{ Name = 'apache'; Image = $ApacheImage; Pull = 'missing' }
            )
        }
        $profiles = if ($orderRandom.Next(0, 2) -eq 0) { @('keepalive', 'no-reuse') } else { @('no-reuse', 'keepalive') }

        foreach ($target in $targets) {
            $env:PERF_IMAGE = $target.Image
            $env:PERF_PULL_POLICY = $target.Pull
            $env:PERF_RUNTIME = $target.Name
            $env:PERF_TRIAL = [string] $trial

            foreach ($profile in $profiles) {
                $label = "$($target.Name)-$profile-trial-$trial"
                $env:PERF_CONNECTION_PROFILE = $profile
                $env:PERF_RUN_LABEL = $label
                Stop-PerformanceStack

                Write-Host "Starting $label with a fresh disposable database..."
                Invoke-Docker ($composeArguments + @('up', '-d', '--wait', '--wait-timeout', '180', 'db', 'app'))

                $containerIds = @(
                    (& docker @composeArguments ps -q app).Trim(),
                    (& docker @composeArguments ps -q db).Trim()
                ) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
                if ($containerIds.Count -ne 2) { throw 'Unable to identify the app and database containers.' }

                $runtimeImageId = (& docker inspect --format '{{.Image}}' $containerIds[0]).Trim()
                if ($LASTEXITCODE -ne 0 -or $runtimeImageId -notmatch '^sha256:[0-9a-f]{64}$') {
                    throw "Unable to identify the runtime image for $label."
                }
                $applicationFingerprint = (& docker @composeArguments exec -T app bash /performance/fingerprint.sh /var/www/html).Trim()
                if ($LASTEXITCODE -ne 0 -or $applicationFingerprint -notmatch '^sha256:[0-9a-f]{64}$') {
                    throw "Unable to fingerprint the application payload for $label."
                }
                $ociLabelsJson = (& docker image inspect $runtimeImageId --format '{{json .Config.Labels}}').Trim()
                if ($LASTEXITCODE -ne 0) { throw "Unable to inspect OCI labels for $label." }
                $ociLabels = if ($ociLabelsJson -eq 'null') { $null } else { $ociLabelsJson | ConvertFrom-Json }
                $ociRevision = if ($null -eq $ociLabels) { $null } else { $ociLabels.'org.opencontainers.image.revision' }
                if ([string]::IsNullOrWhiteSpace($ociRevision) -or $ociRevision -eq '<no value>') {
                    $ociRevision = 'unknown'
                }
                $env:PERF_RUNTIME_IMAGE_ID = $runtimeImageId
                $env:PERF_APPLICATION_FINGERPRINT = $applicationFingerprint
                $env:PERF_OCI_REVISION = $ociRevision

                $seedOutput = & docker @composeArguments exec -T app php /performance/seed.php
                if ($LASTEXITCODE -ne 0) { throw "Dataset seeding failed for $label." }
                $seedOutput | Set-Content -LiteralPath (Join-Path $ResultsDirectory "$label.seed.json") -Encoding utf8

                $statsPath = Join-Path $ResultsDirectory "$label.stats.jsonl"
                $stopPath = Join-Path $ResultsDirectory "$label.stats.stop"
                Remove-Item -LiteralPath $statsPath, $stopPath -Force -ErrorAction SilentlyContinue

                $joinedContainerIds = $containerIds -join ','
                $statsJob = Start-Job -ScriptBlock {
                    param([string] $JoinedContainerIds, [string] $OutputPath, [string] $StopPath, [string] $ProjectName)
                    $ContainerIds = $JoinedContainerIds.Split(',')
                    while (-not (Test-Path -LiteralPath $StopPath)) {
                        $loadGeneratorIds = @(& docker ps -q `
                            --filter "label=com.docker.compose.project=$ProjectName" `
                            --filter 'label=com.docker.compose.service=k6' 2>$null)
                        $sampleIds = @($ContainerIds) + @($loadGeneratorIds) | Where-Object {
                            -not [string]::IsNullOrWhiteSpace($_)
                        }
                        $lines = & docker stats --no-stream --format '{{ json . }}' @sampleIds 2>$null
                        if ($LASTEXITCODE -eq 0 -and $lines) {
                            $lines | Add-Content -LiteralPath $OutputPath -Encoding utf8
                        }
                        Start-Sleep -Seconds 1
                    }
                } -ArgumentList $joinedContainerIds, $statsPath, $stopPath, $projectName

                Write-Host "Running $label..."
                try {
                    & docker @composeArguments --profile load run --rm k6
                    if ($LASTEXITCODE -ne 0) {
                        $runFailed = $true
                        Write-Warning "$label failed its k6 checks or thresholds."
                    }
                } finally {
                    New-Item -ItemType File -Force -Path $stopPath | Out-Null
                    Wait-Job -Job $statsJob -Timeout 15 | Out-Null
                    Receive-Job -Job $statsJob -ErrorAction SilentlyContinue | Out-Null
                    Remove-Job -Job $statsJob -Force -ErrorAction SilentlyContinue
                    Remove-Item -LiteralPath $stopPath -Force -ErrorAction SilentlyContinue
                    if (-not (Test-Path -LiteralPath $statsPath) -or (Get-Item -LiteralPath $statsPath).Length -eq 0) {
                        throw "Docker resource samples were not captured for $label."
                    }
                }
            }
        }
    }
} finally {
    Stop-PerformanceStack
}

Write-Host 'Building machine-readable comparison...'
Invoke-Docker @(
    'run', '--rm', '--entrypoint', 'php', '--user', $LoadGeneratorUser,
    '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
    '-v', "${scriptDirectory}:/performance:ro",
    '-v', "${ResultsDirectory}:/results",
    $ApacheImage,
    '/performance/compare.php', '/results'
)

Write-Host "Performance results: $ResultsDirectory"
if ($runFailed) {
    throw 'One or more performance runs failed their response checks or configured thresholds.'
}
