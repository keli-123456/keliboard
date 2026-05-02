param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseVersion,

    [string]$WorkspaceRoot = '',

    [string]$KelinodeVersion = '',

    [string]$ManifestPath = '',

    [switch]$AllowDirty,

    [switch]$SkipAdminRepo,

    [switch]$SkipKelinodeRepo
)

$ErrorActionPreference = 'Stop'

if ($WorkspaceRoot -eq '') {
    $WorkspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
}

function Assert-Version {
    param(
        [string]$Name,
        [string]$Value
    )

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

function Invoke-GitText {
    param(
        [string]$RepoPath,
        [string[]]$Arguments
    )

    $output = & git -C $RepoPath @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        $message = ($output | Out-String).Trim()
        throw "git -C $RepoPath $($Arguments -join ' ') failed: $message"
    }

    return ($output | Out-String).Trim()
}

function Get-RepoInfo {
    param(
        [string]$Name,
        [string]$RepoPath
    )

    return [ordered]@{
        name = $Name
        path = $RepoPath
        branch = Invoke-GitText $RepoPath @('branch', '--show-current')
        head = Invoke-GitText $RepoPath @('rev-parse', 'HEAD')
        short_head = Invoke-GitText $RepoPath @('rev-parse', '--short', 'HEAD')
        status = Invoke-GitText $RepoPath @('status', '--short', '--branch')
    }
}

function Assert-CleanRepo {
    param(
        [string]$Name,
        [string]$RepoPath
    )

    $dirty = Invoke-GitText $RepoPath @('status', '--porcelain')
    if ($dirty -ne '' -and -not $AllowDirty) {
        throw "$Name has uncommitted changes. Commit, stash, or rerun with -AllowDirty."
    }
}

function Assert-TagMissing {
    param(
        [string]$Name,
        [string]$RepoPath,
        [string]$Tag
    )

    $existing = Invoke-GitText $RepoPath @('tag', '--list', $Tag)
    if ($existing -ne '') {
        throw "$Name already has tag $Tag"
    }
}

function Get-Sha256Short {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        throw "file not found: $Path"
    }

    $stream = [System.IO.File]::OpenRead((Resolve-Path $Path).Path)
    try {
        $sha = [System.Security.Cryptography.SHA256]::Create()
        $bytes = $sha.ComputeHash($stream)
        return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant().Substring(0, 12)
    }
    finally {
        $stream.Dispose()
    }
}

function Assert-AdminAsset {
    param(
        [string]$Kind,
        [string]$AssetPath,
        [string]$PublicPath,
        [string]$IndexHtml
    )

    $version = Get-Sha256Short $AssetPath
    $pattern = '(?:src|href)=["'']' + [regex]::Escape($PublicPath) + '\?v=([a-f0-9]{12})["'']'
    $match = [regex]::Match($IndexHtml, $pattern)

    if (-not $match.Success) {
        throw "admin $Kind asset is not referenced with a 12-char content hash"
    }

    if ($match.Groups[1].Value -ne $version) {
        throw "admin $Kind asset hash mismatch; index.html has $($match.Groups[1].Value), expected $version"
    }

    return $version
}

Assert-Version 'ReleaseVersion' $ReleaseVersion
if ($KelinodeVersion -ne '') {
    Assert-Version 'KelinodeVersion' $KelinodeVersion
}

$keliboard = Resolve-Repo 'keliboard'
$keliAdmin = if ($SkipAdminRepo) { $null } else { Resolve-Repo 'keli-admin' }
$kelinode = if ($SkipKelinodeRepo) { $null } else { Resolve-Repo 'kelinode' }

Assert-CleanRepo 'keliboard' $keliboard
Assert-TagMissing 'keliboard' $keliboard $ReleaseVersion

$adminIndexPath = Join-Path $keliboard 'public/assets/admin-xboard/index.html'
$adminJsPath = Join-Path $keliboard 'public/assets/admin-xboard/assets/index.js'
$adminCssPath = Join-Path $keliboard 'public/assets/admin-xboard/assets/index.css'

if (-not (Test-Path $adminIndexPath)) {
    throw "admin index.html not found: $adminIndexPath"
}

$indexHtml = Get-Content $adminIndexPath -Raw
$adminJsVersion = Assert-AdminAsset 'js' $adminJsPath '/assets/admin-xboard/assets/index.js' $indexHtml
$adminCssVersion = Assert-AdminAsset 'css' $adminCssPath '/assets/admin-xboard/assets/index.css' $indexHtml

$adminJs = Get-Content $adminJsPath -Raw
$bundleGitShaMatch = [regex]::Match($adminJs, 'gitSha:"([^"]+)"')
$bundleBuildIdMatch = [regex]::Match($adminJs, 'buildId:"([^"]+)"')
if (-not $bundleGitShaMatch.Success) {
    throw 'bundled admin gitSha marker not found'
}

$bundleGitSha = $bundleGitShaMatch.Groups[1].Value
$bundleBuildId = if ($bundleBuildIdMatch.Success) { $bundleBuildIdMatch.Groups[1].Value } else { '' }
if ($bundleBuildId -match '-dirty@') {
    throw "bundled admin build was created from a dirty keli-admin tree: $bundleBuildId"
}

$repos = [ordered]@{
    keliboard = Get-RepoInfo 'keliboard' $keliboard
}

if ($null -ne $keliAdmin) {
    Assert-CleanRepo 'keli-admin' $keliAdmin
    $repos.keli_admin = Get-RepoInfo 'keli-admin' $keliAdmin

    if ($bundleGitSha -ne $repos.keli_admin.short_head) {
        throw "bundled admin gitSha $bundleGitSha does not match keli-admin HEAD $($repos.keli_admin.short_head)"
    }
}

$nodeApiContractVersion = $null
if ($null -ne $kelinode) {
    Assert-CleanRepo 'kelinode' $kelinode
    if ($KelinodeVersion -ne '') {
        Assert-TagMissing 'kelinode' $kelinode $KelinodeVersion
    }
    $repos.kelinode = Get-RepoInfo 'kelinode' $kelinode

    $contractJsonPath = Join-Path $keliboard 'contracts/node-api/node-api.json'
    $contractGoPath = Join-Path $kelinode 'api/v2board/contract.go'
    $contractJson = Get-Content $contractJsonPath -Raw | ConvertFrom-Json
    $contractGo = Get-Content $contractGoPath -Raw
    $contractGoMatch = [regex]::Match($contractGo, 'NodeAPIContractVersion\s*=\s*"([^"]+)"')
    if (-not $contractGoMatch.Success) {
        throw 'kelinode NodeAPIContractVersion constant not found'
    }
    if ($contractJson.version -ne $contractGoMatch.Groups[1].Value) {
        throw "node API contract mismatch; keliboard has $($contractJson.version), kelinode has $($contractGoMatch.Groups[1].Value)"
    }
    $nodeApiContractVersion = $contractJson.version
}

$manifest = [ordered]@{
    release_version = $ReleaseVersion
    kelinode_version = if ($KelinodeVersion -ne '') { $KelinodeVersion } else { $null }
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    node_api_contract_version = $nodeApiContractVersion
    admin_bundle = [ordered]@{
        git_sha = $bundleGitSha
        build_id = $bundleBuildId
        asset_versions = [ordered]@{
            js = $adminJsVersion
            css = $adminCssVersion
        }
    }
    repositories = $repos
}

if ($ManifestPath -ne '') {
    $target = if ([System.IO.Path]::IsPathRooted($ManifestPath)) {
        $ManifestPath
    }
    else {
        Join-Path $keliboard $ManifestPath
    }
    $targetDir = Split-Path $target -Parent
    if ($targetDir -ne '' -and -not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir | Out-Null
    }
    $manifest | ConvertTo-Json -Depth 8 | Set-Content -Path $target -Encoding UTF8
}

Write-Host "Release preflight passed" -ForegroundColor Green
Write-Host "Release: $ReleaseVersion"
Write-Host "keliboard: $($repos.keliboard.short_head)"
if ($repos.Contains('keli_admin')) {
    Write-Host "keli-admin: $($repos.keli_admin.short_head) (bundled: $bundleGitSha)"
}
if ($repos.Contains('kelinode')) {
    Write-Host "kelinode: $($repos.kelinode.short_head)"
}
if ($nodeApiContractVersion) {
    Write-Host "node-api contract: $nodeApiContractVersion"
}
Write-Host "admin assets: js=$adminJsVersion css=$adminCssVersion"
if ($ManifestPath -ne '') {
    Write-Host "manifest: $target"
}
