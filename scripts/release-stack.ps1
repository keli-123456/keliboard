param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseVersion,

    [string]$WorkspaceRoot = '',

    [string]$KelinodeVersion = '',

    [switch]$SkipAdminBuild,

    [switch]$SkipKelinodeRepo,

    [switch]$CommitSyncedAssets,

    [switch]$Tag,

    [switch]$Push,

    [switch]$AllowDirty
)

$ErrorActionPreference = 'Stop'

if ($WorkspaceRoot -eq '') {
    $WorkspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
}

function Assert-Version {
    param([string]$Name, [string]$Value)
    if ($Value -notmatch '^v\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$') {
        throw "$Name must be a semver tag like v0.4.0; got: $Value"
    }
}

function Resolve-Repo {
    param([string]$Name)
    $path = Join-Path $WorkspaceRoot $Name
    if (-not (Test-Path (Join-Path $path '.git'))) {
        throw "repository not found: $path"
    }
    return (Resolve-Path $path).Path
}

function Invoke-Checked {
    param([string]$WorkingDirectory, [string]$FilePath, [string[]]$Arguments)
    Write-Host "> $FilePath $($Arguments -join ' ')" -ForegroundColor DarkCyan
    $output = & $FilePath @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        $message = ($output | Out-String).Trim()
        throw "command failed in ${WorkingDirectory}: $FilePath $($Arguments -join ' ')`n$message"
    }
    if ($output) {
        $output | Write-Host
    }
}

function Invoke-InRepo {
    param([string]$RepoPath, [string]$FilePath, [string[]]$Arguments)
    Push-Location $RepoPath
    try {
        Invoke-Checked $RepoPath $FilePath $Arguments
    }
    finally {
        Pop-Location
    }
}

function Git-Text {
    param([string]$RepoPath, [string[]]$Arguments)
    $output = & git -C $RepoPath @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "git -C $RepoPath $($Arguments -join ' ') failed: $(($output | Out-String).Trim())"
    }
    return ($output | Out-String).Trim()
}

function Assert-CleanRepo {
    param([string]$Name, [string]$RepoPath)
    $dirty = Git-Text $RepoPath @('status', '--porcelain')
    if ($dirty -ne '' -and -not $AllowDirty) {
        throw "$Name has uncommitted changes. Commit, stash, or rerun with -AllowDirty."
    }
}

function Assert-TagMissing {
    param([string]$Name, [string]$RepoPath, [string]$TagName)
    $existing = Git-Text $RepoPath @('tag', '--list', $TagName)
    if ($existing -ne '') {
        throw "$Name already has tag $TagName"
    }
}

function Commit-AdminAssetsIfNeeded {
    param([string]$KeliboardPath)
    $dirtyAssets = Git-Text $KeliboardPath @('status', '--porcelain', '--', 'public/assets/admin-xboard')
    if ($dirtyAssets -eq '') {
        return
    }

    if (-not $CommitSyncedAssets) {
        Write-Host "Admin assets changed in keliboard. Review and commit them, or rerun with -CommitSyncedAssets." -ForegroundColor Yellow
        return
    }

    Invoke-Checked $KeliboardPath 'git' @('-C', $KeliboardPath, 'add', 'public/assets/admin-xboard')
    Invoke-Checked $KeliboardPath 'git' @('-C', $KeliboardPath, 'commit', '-m', "Build admin assets for $ReleaseVersion")
}

Assert-Version 'ReleaseVersion' $ReleaseVersion
if ($KelinodeVersion -ne '') {
    Assert-Version 'KelinodeVersion' $KelinodeVersion
}

$keliboard = Resolve-Repo 'keliboard'
$keliAdmin = Resolve-Repo 'keli-admin'
$kelinode = if ($SkipKelinodeRepo) { $null } else { Resolve-Repo 'kelinode' }

if (-not $SkipAdminBuild) {
    Assert-CleanRepo 'keli-admin' $keliAdmin
    Invoke-InRepo $keliAdmin 'npm' @('run', 'build')
    Invoke-InRepo $keliAdmin 'node' @('scripts/sync-to-xboardpro.mjs')
    Commit-AdminAssetsIfNeeded $keliboard
}

Invoke-InRepo $keliboard 'php' @('scripts/verify-admin-xboard-assets.php')
Invoke-InRepo $keliboard 'php' @('scripts/verify-composer-platform.php')

$preflightArgs = @(
    '-ExecutionPolicy', 'Bypass',
    '-File', (Join-Path $keliboard 'scripts/release-preflight.ps1'),
    '-ReleaseVersion', $ReleaseVersion,
    '-WorkspaceRoot', $WorkspaceRoot
)
if ($KelinodeVersion -ne '') {
    $preflightArgs += @('-KelinodeVersion', $KelinodeVersion)
}
if ($SkipKelinodeRepo) {
    $preflightArgs += '-SkipKelinodeRepo'
}
if ($AllowDirty) {
    $preflightArgs += '-AllowDirty'
}
Invoke-Checked $keliboard 'powershell' $preflightArgs

if ($Tag) {
    if ($AllowDirty) {
        throw 'Refusing to create tags with -AllowDirty.'
    }
    Assert-CleanRepo 'keliboard' $keliboard
    Assert-CleanRepo 'keli-admin' $keliAdmin
    if ($null -ne $kelinode) {
        Assert-CleanRepo 'kelinode' $kelinode
    }
    Assert-TagMissing 'keliboard' $keliboard $ReleaseVersion
    Assert-TagMissing 'keli-admin' $keliAdmin $ReleaseVersion
    Invoke-Checked $keliboard 'git' @('-C', $keliboard, 'tag', '-a', $ReleaseVersion, '-m', "Release $ReleaseVersion")
    Invoke-Checked $keliAdmin 'git' @('-C', $keliAdmin, 'tag', '-a', $ReleaseVersion, '-m', "Release $ReleaseVersion")

    if ($null -ne $kelinode -and $KelinodeVersion -ne '') {
        Assert-TagMissing 'kelinode' $kelinode $KelinodeVersion
        Invoke-Checked $kelinode 'git' @('-C', $kelinode, 'tag', '-a', $KelinodeVersion, '-m', "Release $KelinodeVersion")
    }
}

if ($Push) {
    if (-not $Tag) {
        throw 'Use -Tag together with -Push so the pushed refs are explicit.'
    }
    Invoke-Checked $keliboard 'git' @('-C', $keliboard, 'push', 'origin', 'HEAD', $ReleaseVersion)
    Invoke-Checked $keliAdmin 'git' @('-C', $keliAdmin, 'push', 'origin', 'HEAD', $ReleaseVersion)
    if ($null -ne $kelinode -and $KelinodeVersion -ne '') {
        Invoke-Checked $kelinode 'git' @('-C', $kelinode, 'push', 'origin', 'HEAD', $KelinodeVersion)
    }
}

Write-Host "Release stack workflow passed for $ReleaseVersion" -ForegroundColor Green
