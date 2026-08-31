[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseVersion,

    [ValidateSet('Prepare', 'Verify', 'Publish')]
    [string]$Mode = 'Prepare',

    [string]$WorkspaceRoot = '',
    [string]$PhpPath = '',
    [string]$UserThemePath = '',
    [string]$KelinodeRsManifestPath = '',
    [string]$NativeClientManifestPath = '',
    [string]$NativeClientArtifactPath = '',

    [switch]$SkipTests,
    [switch]$SkipAdminBuild,
    [switch]$SkipUserBuild,
    [switch]$CommitSyncedAssets,
    [switch]$Push,
    [switch]$PlanOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($WorkspaceRoot -eq '') {
    $WorkspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
}
$WorkspaceRoot = (Resolve-Path -LiteralPath $WorkspaceRoot).Path

if ($ReleaseVersion -notmatch '^v\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$') {
    throw "ReleaseVersion must be a semantic version such as v1.2.3; got: $ReleaseVersion"
}

function Resolve-Repo {
    param([Parameter(Mandatory = $true)][string]$Name)

    $path = Join-Path $WorkspaceRoot $Name
    if (-not (Test-Path -LiteralPath (Join-Path $path '.git'))) {
        throw "repository not found: $path"
    }
    return (Resolve-Path -LiteralPath $path).Path
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    Write-Host "> $FilePath $($Arguments -join ' ')" -ForegroundColor DarkCyan
    Push-Location $WorkingDirectory
    try {
        $output = & $FilePath @Arguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "command failed in ${WorkingDirectory}: $FilePath $($Arguments -join ' ')`n$(($output | Out-String).Trim())"
        }
        if ($output) {
            $output | Write-Host
        }
    } finally {
        Pop-Location
    }
}

function Git-Text {
    param(
        [Parameter(Mandatory = $true)][string]$RepoPath,
        [Parameter(Mandatory = $true)][string[]]$Arguments
    )

    $output = & git -C $RepoPath @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "git -C $RepoPath $($Arguments -join ' ') failed: $(($output | Out-String).Trim())"
    }
    return ($output | Out-String).Trim()
}

function Assert-TrackedClean {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$RepoPath
    )

    $dirty = Git-Text -RepoPath $RepoPath -Arguments @('status', '--porcelain', '--untracked-files=no')
    if ($dirty -ne '') {
        throw "$Name has tracked uncommitted changes; full-stack releases require committed source."
    }
}

function Ensure-ReleaseTag {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$RepoPath
    )

    $existing = Git-Text -RepoPath $RepoPath -Arguments @('tag', '--list', $ReleaseVersion)
    if ($existing -ne '') {
        $tagSha = Git-Text -RepoPath $RepoPath -Arguments @('rev-list', '-n', '1', $ReleaseVersion)
        $headSha = Git-Text -RepoPath $RepoPath -Arguments @('rev-parse', 'HEAD')
        if ($tagSha -ne $headSha) {
            throw "$Name tag $ReleaseVersion points to $tagSha instead of HEAD $headSha"
        }
        Write-Host "$Name already has the correct local tag $ReleaseVersion" -ForegroundColor DarkGray
        return
    }

    Invoke-Checked -WorkingDirectory $RepoPath -FilePath 'git' -Arguments @('tag', '-a', $ReleaseVersion, '-m', "Keli full-stack release $ReleaseVersion")
}

function Resolve-PhpExecutable {
    if ($PhpPath -ne '') {
        if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
            throw "PHP executable not found: $PhpPath"
        }
        return (Resolve-Path -LiteralPath $PhpPath).Path
    }
    if (-not [string]::IsNullOrWhiteSpace($env:KELI_PHP)) {
        if (-not (Test-Path -LiteralPath $env:KELI_PHP -PathType Leaf)) {
            throw "KELI_PHP executable not found: $($env:KELI_PHP)"
        }
        return (Resolve-Path -LiteralPath $env:KELI_PHP).Path
    }
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $php) {
        throw 'PHP is required. Pass -PhpPath or set KELI_PHP.'
    }
    return $php.Source
}

$repos = [ordered]@{
    keliboard = Resolve-Repo 'keliboard'
    keli_admin = Resolve-Repo 'keli-admin'
    keli_user = Resolve-Repo 'keli-user'
    kelinode_rs = Resolve-Repo 'kelinode-rs'
    keli_core_rs = Resolve-Repo 'keli-core-rs'
    keli_native_client = Resolve-Repo 'keli-native-client'
}

$candidateDirectory = Join-Path $repos.keliboard "build\releases\$ReleaseVersion"
$manifestPath = Join-Path $candidateDirectory 'release-manifest.json'
if ($UserThemePath -eq '') {
    $UserThemePath = Join-Path $candidateDirectory "xboard-custom-theme-$ReleaseVersion.zip"
} elseif (-not [System.IO.Path]::IsPathRooted($UserThemePath)) {
    $UserThemePath = Join-Path $WorkspaceRoot $UserThemePath
}

if ($PlanOnly) {
    Write-Output "mode $Mode"
    Write-Output 'source keliboard keli-admin keli-user kelinode-rs keli-core-rs keli-native-client'
    Write-Output "candidate $candidateDirectory"
    Write-Output "manifest $manifestPath"
    Write-Output "theme $UserThemePath"
    Write-Output 'prepare test build sync package generate-candidate-manifest'
    Write-Output 'verify require-clean-source require-four-artifacts verify-contracts verify-sha256'
    Write-Output 'publish verify tag-six-repositories push-optional'
    return
}

$phpExecutable = Resolve-PhpExecutable

function Assert-AllSourcesClean {
    foreach ($entry in $repos.GetEnumerator()) {
        Assert-TrackedClean -Name $entry.Key -RepoPath $entry.Value
    }
}

function Invoke-ReleaseTests {
    if ($SkipTests) {
        return
    }

    Invoke-Checked -WorkingDirectory $repos.keli_admin -FilePath 'npm' -Arguments @('run', 'test')
    Invoke-Checked -WorkingDirectory $repos.keli_admin -FilePath 'npm' -Arguments @('run', 'verify:reproducible')
    Invoke-Checked -WorkingDirectory $repos.keli_user -FilePath 'npm' -Arguments @('run', 'test')
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/verify-composer-platform.php')
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/verify-release-manifest.tests.php')
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/release-smoke.tests.php')
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/verify-deployment-receipt.tests.php')
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/deploy-release.tests.php')
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/deploy-release.integration.tests.php')
    Invoke-Checked -WorkingDirectory $repos.keli_core_rs -FilePath 'cargo' -Arguments @('test', '--locked', '--all-targets', '--', '--test-threads=1')
    Invoke-Checked -WorkingDirectory $repos.kelinode_rs -FilePath 'cargo' -Arguments @('test', '--locked', '--test', 'release_manifest_contract', '--test', 'install_script_source', '--', '--test-threads=1')
    Invoke-Checked -WorkingDirectory $repos.keli_native_client -FilePath 'powershell' -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/tauri-client-update-manifest.tests.ps1')
    Invoke-Checked -WorkingDirectory $repos.keli_native_client -FilePath 'powershell' -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'scripts/desktop-package.tests.ps1')
}

function Invoke-StrictPreflight {
    foreach ($requiredPath in @($UserThemePath, $KelinodeRsManifestPath, $NativeClientManifestPath, $NativeClientArtifactPath)) {
        if ([string]::IsNullOrWhiteSpace($requiredPath)) {
            throw 'Verify and Publish require user theme, kelinode-rs manifest, native client manifest, and native client artifact paths.'
        }
    }

    $arguments = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', (Join-Path $repos.keliboard 'scripts/release-preflight.ps1'),
        '-ReleaseVersion', $ReleaseVersion,
        '-WorkspaceRoot', $WorkspaceRoot,
        '-FullStack',
        '-ManifestPath', $manifestPath,
        '-UserThemePath', $UserThemePath,
        '-KelinodeRsManifestPath', $KelinodeRsManifestPath,
        '-NativeClientManifestPath', $NativeClientManifestPath,
        '-NativeClientArtifactPath', $NativeClientArtifactPath,
        '-PhpPath', $phpExecutable
    )
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath 'powershell' -Arguments $arguments
}

if ($Mode -eq 'Prepare') {
    Assert-AllSourcesClean
    Invoke-ReleaseTests
    New-Item -ItemType Directory -Force -Path $candidateDirectory | Out-Null

    if (-not $SkipAdminBuild) {
        Invoke-Checked -WorkingDirectory $repos.keli_admin -FilePath 'npm' -Arguments @('run', 'build')
        Invoke-Checked -WorkingDirectory $repos.keli_admin -FilePath 'node' -Arguments @('scripts/sync-to-xboardpro.mjs')
        Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath $phpExecutable -Arguments @('scripts/verify-admin-xboard-assets.php')
    }

    if (-not $SkipUserBuild) {
        Invoke-Checked -WorkingDirectory $repos.keli_user -FilePath 'npm' -Arguments @('run', 'build')
        $oldThemeVersion = $env:THEME_VERSION
        $oldThemeSourceSha = $env:THEME_SOURCE_GIT_SHA
        $oldThemeSourceDirty = $env:THEME_SOURCE_DIRTY
        $oldThemeZipPath = $env:THEME_ZIP_PATH
        try {
            $env:THEME_VERSION = $ReleaseVersion
            $env:THEME_SOURCE_GIT_SHA = Git-Text -RepoPath $repos.keli_user -Arguments @('rev-parse', 'HEAD')
            $env:THEME_SOURCE_DIRTY = 'false'
            $env:THEME_ZIP_PATH = $UserThemePath
            Invoke-Checked -WorkingDirectory $repos.keli_user -FilePath 'npm' -Arguments @('run', 'package:xboard')
        } finally {
            $env:THEME_VERSION = $oldThemeVersion
            $env:THEME_SOURCE_GIT_SHA = $oldThemeSourceSha
            $env:THEME_SOURCE_DIRTY = $oldThemeSourceDirty
            $env:THEME_ZIP_PATH = $oldThemeZipPath
        }
    }

    if ($CommitSyncedAssets) {
        $assetStatus = Git-Text -RepoPath $repos.keliboard -Arguments @('status', '--porcelain', '--', 'public/assets/admin-xboard')
        if ($assetStatus -ne '') {
            Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath 'git' -Arguments @('add', 'public/assets/admin-xboard')
            Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath 'git' -Arguments @('commit', '-m', "Build admin assets for $ReleaseVersion")
        }
    }

    $generatorArguments = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', (Join-Path $repos.keliboard 'scripts/generate-release-manifest.ps1'),
        '-ReleaseVersion', $ReleaseVersion,
        '-WorkspaceRoot', $WorkspaceRoot,
        '-OutputPath', $manifestPath,
        '-UserThemePath', $UserThemePath,
        '-PhpPath', $phpExecutable
    )
    if ($KelinodeRsManifestPath -ne '') {
        $generatorArguments += @('-KelinodeRsManifestPath', $KelinodeRsManifestPath)
    }
    if ($NativeClientManifestPath -ne '') {
        $generatorArguments += @('-NativeClientManifestPath', $NativeClientManifestPath)
    }
    if ($NativeClientArtifactPath -ne '') {
        $generatorArguments += @('-NativeClientArtifactPath', $NativeClientArtifactPath)
    }
    Invoke-Checked -WorkingDirectory $repos.keliboard -FilePath 'powershell' -Arguments $generatorArguments

    Write-Host "Release candidate prepared: $candidateDirectory" -ForegroundColor Green
    if (-not $CommitSyncedAssets) {
        Write-Host 'Review and commit synced admin assets, then run -Mode Verify with all artifact paths.' -ForegroundColor Yellow
    }
    return
}

Assert-AllSourcesClean
Invoke-ReleaseTests
Invoke-StrictPreflight

if ($Mode -eq 'Verify') {
    Write-Host "Full-stack release verified: $ReleaseVersion" -ForegroundColor Green
    return
}

foreach ($entry in $repos.GetEnumerator()) {
    Ensure-ReleaseTag -Name $entry.Key -RepoPath $entry.Value
}

if ($Push) {
    foreach ($entry in $repos.GetEnumerator()) {
        Invoke-Checked -WorkingDirectory $entry.Value -FilePath 'git' -Arguments @('push', 'origin', 'HEAD', $ReleaseVersion)
    }
}

Write-Host "Full-stack release tagged: $ReleaseVersion" -ForegroundColor Green
if (-not $Push) {
    Write-Host 'Tags were created locally. Rerun with -Mode Publish -Push only after review.' -ForegroundColor Yellow
}
