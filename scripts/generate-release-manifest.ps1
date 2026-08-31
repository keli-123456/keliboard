[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseVersion,

    [string]$WorkspaceRoot = '',

    [string]$OutputPath = '',

    [string]$UserThemePath = '',

    [string]$KelinodeRsManifestPath = '',

    [string]$NativeClientManifestPath = '',

    [string]$NativeClientArtifactPath = '',

    [string]$PhpPath = '',

    [switch]$Strict,

    [switch]$IncludeLegacyKelinode
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

if ($OutputPath -eq '') {
    $OutputPath = Join-Path $WorkspaceRoot "keliboard\build\releases\$ReleaseVersion\release-manifest.json"
} elseif (-not [System.IO.Path]::IsPathRooted($OutputPath)) {
    $OutputPath = Join-Path $WorkspaceRoot $OutputPath
}

function Resolve-RepoPath {
    param([Parameter(Mandatory = $true)][string]$Name)

    $path = Join-Path $WorkspaceRoot $Name
    if (-not (Test-Path -LiteralPath (Join-Path $path '.git'))) {
        throw "repository not found: $path"
    }
    return (Resolve-Path -LiteralPath $path).Path
}

function Invoke-GitText {
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

function Get-WorkspaceRelativePath {
    param([Parameter(Mandatory = $true)][string]$Path)

    $fullPath = [System.IO.Path]::GetFullPath($Path)
    $root = [System.IO.Path]::GetFullPath($WorkspaceRoot).TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
    if (-not $fullPath.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "release evidence must be inside the workspace: $fullPath"
    }
    return $fullPath.Substring($root.Length).Replace('\', '/')
}

function Get-FileEvidence {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "release evidence file not found: $Path"
    }
    $item = Get-Item -LiteralPath $Path
    return [ordered]@{
        path = Get-WorkspaceRelativePath -Path $item.FullName
        bytes = [long]$item.Length
        sha256 = (Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

function Get-RepositoryVersion {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$RepoPath
    )

    if ($Name -eq 'keliboard') {
        $appConfig = Get-Content -Raw -LiteralPath (Join-Path $RepoPath 'config/app.php')
        $match = [regex]::Match($appConfig, "'version'\s*=>\s*'([^']+)'")
        return $(if ($match.Success) { $match.Groups[1].Value } else { $null })
    }
    if ($Name -in @('keli-admin', 'keli-user')) {
        $package = Get-Content -Raw -LiteralPath (Join-Path $RepoPath 'package.json') | ConvertFrom-Json
        return [string]$package.version
    }
    if (Test-Path -LiteralPath (Join-Path $RepoPath 'Cargo.toml')) {
        $cargo = Get-Content -Raw -LiteralPath (Join-Path $RepoPath 'Cargo.toml')
        $match = [regex]::Match($cargo, '(?m)^version\s*=\s*"([^"]+)"')
        return $(if ($match.Success) { $match.Groups[1].Value } else { $null })
    }
    return $null
}

function Get-RepositoryInfo {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$RepoPath,
        [string[]]$LockFiles = @()
    )

    $statusOutput = Invoke-GitText -RepoPath $RepoPath -Arguments @('status', '--porcelain', '--untracked-files=all')
    $statusLines = @()
    if ($statusOutput -ne '') {
        $statusLines = @($statusOutput -split "`r?`n")
    }
    $trackedChanges = @($statusLines | Where-Object { $_ -notmatch '^\?\? ' })
    $untrackedBuildInputs = @($statusLines | Where-Object {
        if ($_ -notmatch '^\?\? (.+)$') {
            return $false
        }
        $path = $Matches[1].Replace('\', '/')
        if ($path -match '^(audit|design-audits|dist|artifacts|target|\.theme_build)/') {
            return $false
        }
        if ($path -match '(^|/)(dev_server\.(out|err)\.log|[^/]+\.(png|jpg|jpeg|gif|zip|tgz|tar\.gz))$') {
            return $false
        }
        return $path -match '\.(php|ts|tsx|js|jsx|mjs|cjs|rs|toml|lock|json|ya?ml|sh|ps1|css|scss|html|md)$'
    })
    $dirty = $trackedChanges.Count -gt 0 -or $untrackedBuildInputs.Count -gt 0

    $locks = @()
    foreach ($lockFile in $LockFiles) {
        $path = Join-Path $RepoPath $lockFile
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            throw "$Name dependency lock not found: $lockFile"
        }
        $locks += Get-FileEvidence -Path $path
    }

    return [ordered]@{
        name = $Name
        branch = Invoke-GitText -RepoPath $RepoPath -Arguments @('branch', '--show-current')
        git_sha = Invoke-GitText -RepoPath $RepoPath -Arguments @('rev-parse', 'HEAD')
        dirty = [bool]$dirty
        version = Get-RepositoryVersion -Name $Name -RepoPath $RepoPath
        dependency_locks = @($locks)
    }
}

function New-ArtifactGroup {
    param(
        [Parameter(Mandatory = $true)][string]$Status,
        [Parameter(Mandatory = $true)][string]$SourceGitSha,
        [AllowNull()][string]$Version = $null,
        [object[]]$Files = @(),
        [hashtable]$Metadata = @{}
    )

    return [ordered]@{
        status = $Status
        source_git_sha = $SourceGitSha
        version = $Version
        files = @($Files)
        metadata = $Metadata
    }
}

function Get-AdminBundleArtifact {
    param(
        [Parameter(Mandatory = $true)][string]$KeliboardPath,
        [Parameter(Mandatory = $true)][hashtable]$AdminRepository
    )

    $root = Join-Path $KeliboardPath 'public/assets/admin-xboard'
    $indexPath = Join-Path $root 'index.html'
    $jsPath = Join-Path $root 'assets/index.js'
    $cssPath = Join-Path $root 'assets/index.css'
    $buildManifestPath = Join-Path $root 'build-manifest.json'
    $files = @()
    foreach ($path in @($indexPath, $jsPath, $cssPath, $buildManifestPath)) {
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            $files += Get-FileEvidence -Path $path
        }
    }

    $status = 'failed'
    $metadata = [ordered]@{}
    if ($files.Count -eq 4) {
        $index = Get-Content -Raw -LiteralPath $indexPath
        $buildManifest = Get-Content -Raw -LiteralPath $buildManifestPath | ConvertFrom-Json
        $jsHash = (Get-FileHash -LiteralPath $jsPath -Algorithm SHA256).Hash.ToLowerInvariant().Substring(0, 12)
        $cssHash = (Get-FileHash -LiteralPath $cssPath -Algorithm SHA256).Hash.ToLowerInvariant().Substring(0, 12)
        $jsRef = [regex]::Match($index, '(?:src|href)=["'']\/assets\/admin-xboard\/assets\/index\.js\?v=([a-f0-9]{12})["'']')
        $cssRef = [regex]::Match($index, '(?:src|href)=["'']\/assets\/admin-xboard\/assets\/index\.css\?v=([a-f0-9]{12})["'']')
        $bundleGitSha = [string]$buildManifest.source_git_sha
        $manifestJsSha = [string]$buildManifest.files.'assets/index.js'.sha256
        $manifestCssSha = [string]$buildManifest.files.'assets/index.css'.sha256
        $manifestIndexSha = [string]$buildManifest.files.'index.html'.sha256
        $metadata = [ordered]@{
            bundle_git_sha = $bundleGitSha
            source_dirty = [bool]$buildManifest.source_dirty
            generated_at = [string]$buildManifest.generated_at
            js_asset_version = $jsHash
            css_asset_version = $cssHash
        }
        $actualIndexSha = (Get-FileHash -LiteralPath $indexPath -Algorithm SHA256).Hash.ToLowerInvariant()
        $actualJsSha = (Get-FileHash -LiteralPath $jsPath -Algorithm SHA256).Hash.ToLowerInvariant()
        $actualCssSha = (Get-FileHash -LiteralPath $cssPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($bundleGitSha -eq [string]$AdminRepository.git_sha -and -not [bool]$buildManifest.source_dirty -and
            $manifestIndexSha -eq $actualIndexSha -and $manifestJsSha -eq $actualJsSha -and $manifestCssSha -eq $actualCssSha -and
            $jsRef.Success -and $cssRef.Success -and $jsRef.Groups[1].Value -eq $jsHash -and $cssRef.Groups[1].Value -eq $cssHash) {
            $status = 'passed'
        }
    }

    return New-ArtifactGroup -Status $status -SourceGitSha $AdminRepository.git_sha -Version $AdminRepository.version -Files $files -Metadata $metadata
}

function Read-ZipJsonEntry {
    param(
        [Parameter(Mandatory = $true)][string]$ZipPath,
        [Parameter(Mandatory = $true)][string]$EntryName
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        $entry = $archive.Entries | Where-Object { $_.FullName -eq $EntryName } | Select-Object -First 1
        if ($null -eq $entry) {
            return $null
        }
        $reader = New-Object System.IO.StreamReader($entry.Open())
        try {
            return ($reader.ReadToEnd() | ConvertFrom-Json)
        } finally {
            $reader.Dispose()
        }
    } finally {
        $archive.Dispose()
    }
}

function Get-UserThemeArtifact {
    param(
        [Parameter(Mandatory = $true)][hashtable]$UserRepository,
        [string]$ThemePath
    )

    if ($ThemePath -eq '') {
        return New-ArtifactGroup -Status 'skipped' -SourceGitSha $UserRepository.git_sha -Version $UserRepository.version
    }
    if (-not [System.IO.Path]::IsPathRooted($ThemePath)) {
        $ThemePath = Join-Path $WorkspaceRoot $ThemePath
    }
    if (-not (Test-Path -LiteralPath $ThemePath -PathType Leaf)) {
        return New-ArtifactGroup -Status 'failed' -SourceGitSha $UserRepository.git_sha -Version $UserRepository.version
    }

    $config = Read-ZipJsonEntry -ZipPath $ThemePath -EntryName 'custom/config.json'
    $sourceGitSha = $(if ($null -ne $config -and $null -ne $config.PSObject.Properties['source_git_sha']) { [string]$config.source_git_sha } else { '' })
    $sourceDirty = $(if ($null -ne $config -and $null -ne $config.PSObject.Properties['source_dirty']) { [bool]$config.source_dirty } else { $true })
    $status = $(if ($sourceGitSha -eq [string]$UserRepository.git_sha -and -not $sourceDirty) { 'passed' } else { 'failed' })
    $metadata = [ordered]@{
        theme_name = $(if ($null -ne $config) { [string]$config.name } else { '' })
        theme_version = $(if ($null -ne $config) { [string]$config.version } else { '' })
        source_git_sha = $sourceGitSha
        source_dirty = $sourceDirty
    }
    return New-ArtifactGroup -Status $status -SourceGitSha $UserRepository.git_sha -Version $metadata.theme_version -Files @((Get-FileEvidence -Path $ThemePath)) -Metadata $metadata
}

function Get-KelinodeRsArtifact {
    param(
        [Parameter(Mandatory = $true)][hashtable]$NodeRepository,
        [Parameter(Mandatory = $true)][hashtable]$CoreRepository,
        [string]$ManifestPath
    )

    if ($ManifestPath -eq '') {
        return New-ArtifactGroup -Status 'skipped' -SourceGitSha $NodeRepository.git_sha -Version $NodeRepository.version
    }
    if (-not [System.IO.Path]::IsPathRooted($ManifestPath)) {
        $ManifestPath = Join-Path $WorkspaceRoot $ManifestPath
    }
    if (-not (Test-Path -LiteralPath $ManifestPath -PathType Leaf)) {
        return New-ArtifactGroup -Status 'failed' -SourceGitSha $NodeRepository.git_sha -Version $NodeRepository.version
    }

    $manifest = Get-Content -Raw -LiteralPath $ManifestPath | ConvertFrom-Json
    $archivePath = Join-Path (Split-Path -Parent $ManifestPath) ([string]$manifest.archive)
    $files = @((Get-FileEvidence -Path $ManifestPath))
    $status = 'failed'
    if (Test-Path -LiteralPath $archivePath -PathType Leaf) {
        $archiveEvidence = Get-FileEvidence -Path $archivePath
        $files += $archiveEvidence
        $nodeSourceSha = $(if ($null -ne $manifest.PSObject.Properties['source']) { [string]$manifest.source.kelinode_rs_git_sha } else { '' })
        $coreSourceSha = $(if ($null -ne $manifest.PSObject.Properties['source']) { [string]$manifest.source.keli_core_rs_git_sha } else { '' })
        $nodeSourceDirty = $(if ($null -ne $manifest.PSObject.Properties['source']) { [bool]$manifest.source.kelinode_rs_dirty } else { $true })
        $coreSourceDirty = $(if ($null -ne $manifest.PSObject.Properties['source']) { [bool]$manifest.source.keli_core_rs_dirty } else { $true })
        if ($archiveEvidence.sha256 -eq [string]$manifest.sha256 -and
            $nodeSourceSha -eq [string]$NodeRepository.git_sha -and
            $coreSourceSha -eq [string]$CoreRepository.git_sha -and
            -not $nodeSourceDirty -and -not $coreSourceDirty) {
            $status = 'passed'
        }
    }
    $metadata = [ordered]@{
        platform = [string]$manifest.platform
        binary_sha256 = [string]$manifest.binary_sha256
        embedded_core = [string]$manifest.default_core
        kelinode_rs_git_sha = $(if ($null -ne $manifest.PSObject.Properties['source']) { [string]$manifest.source.kelinode_rs_git_sha } else { '' })
        keli_core_rs_git_sha = $(if ($null -ne $manifest.PSObject.Properties['source']) { [string]$manifest.source.keli_core_rs_git_sha } else { '' })
        kelinode_rs_dirty = $(if ($null -ne $manifest.PSObject.Properties['source']) { [bool]$manifest.source.kelinode_rs_dirty } else { $true })
        keli_core_rs_dirty = $(if ($null -ne $manifest.PSObject.Properties['source']) { [bool]$manifest.source.keli_core_rs_dirty } else { $true })
    }
    return New-ArtifactGroup -Status $status -SourceGitSha $NodeRepository.git_sha -Version ([string]$manifest.version) -Files $files -Metadata $metadata
}

function Get-NativeClientArtifact {
    param(
        [Parameter(Mandatory = $true)][hashtable]$ClientRepository,
        [string]$ManifestPath,
        [string]$ArtifactPath
    )

    if ($ManifestPath -eq '' -or $ArtifactPath -eq '') {
        return New-ArtifactGroup -Status 'skipped' -SourceGitSha $ClientRepository.git_sha -Version $ClientRepository.version
    }
    if (-not [System.IO.Path]::IsPathRooted($ManifestPath)) {
        $ManifestPath = Join-Path $WorkspaceRoot $ManifestPath
    }
    if (-not [System.IO.Path]::IsPathRooted($ArtifactPath)) {
        $ArtifactPath = Join-Path $WorkspaceRoot $ArtifactPath
    }
    if (-not (Test-Path -LiteralPath $ManifestPath -PathType Leaf) -or -not (Test-Path -LiteralPath $ArtifactPath -PathType Leaf)) {
        return New-ArtifactGroup -Status 'failed' -SourceGitSha $ClientRepository.git_sha -Version $ClientRepository.version
    }

    $manifest = Get-Content -Raw -LiteralPath $ManifestPath | ConvertFrom-Json
    $artifactEvidence = Get-FileEvidence -Path $ArtifactPath
    $manifestEvidence = Get-FileEvidence -Path $ManifestPath
    $manifestSourceSha = $(if ($null -ne $manifest.keli.PSObject.Properties['source_git_sha']) { [string]$manifest.keli.source_git_sha } else { '' })
    $manifestSourceDirty = $(if ($null -ne $manifest.keli.PSObject.Properties['source_dirty']) { [bool]$manifest.keli.source_dirty } else { $true })
    $status = $(if ($artifactEvidence.sha256 -eq [string]$manifest.keli.sha256 -and $manifestSourceSha -eq [string]$ClientRepository.git_sha -and -not $manifestSourceDirty) { 'passed' } else { 'failed' })
    $metadata = [ordered]@{
        channel = [string]$manifest.keli.channel
        installer = [string]$manifest.keli.installer
        signature_present = [bool]$manifest.keli.signature_present
        source_git_sha = $manifestSourceSha
        source_dirty = $manifestSourceDirty
    }
    return New-ArtifactGroup -Status $status -SourceGitSha $ClientRepository.git_sha -Version ([string]$manifest.version) -Files @($manifestEvidence, $artifactEvidence) -Metadata $metadata
}

$paths = [ordered]@{
    keliboard = Resolve-RepoPath -Name 'keliboard'
    keli_admin = Resolve-RepoPath -Name 'keli-admin'
    keli_user = Resolve-RepoPath -Name 'keli-user'
    kelinode_rs = Resolve-RepoPath -Name 'kelinode-rs'
    keli_core_rs = Resolve-RepoPath -Name 'keli-core-rs'
    keli_native_client = Resolve-RepoPath -Name 'keli-native-client'
}

$repositories = [ordered]@{
    keliboard = Get-RepositoryInfo -Name 'keliboard' -RepoPath $paths.keliboard -LockFiles @('composer.lock')
    keli_admin = Get-RepositoryInfo -Name 'keli-admin' -RepoPath $paths.keli_admin -LockFiles @('package-lock.json')
    keli_user = Get-RepositoryInfo -Name 'keli-user' -RepoPath $paths.keli_user -LockFiles @('package-lock.json')
    kelinode_rs = Get-RepositoryInfo -Name 'kelinode-rs' -RepoPath $paths.kelinode_rs -LockFiles @('Cargo.lock')
    keli_core_rs = Get-RepositoryInfo -Name 'keli-core-rs' -RepoPath $paths.keli_core_rs -LockFiles @('Cargo.lock')
    keli_native_client = Get-RepositoryInfo -Name 'keli-native-client' -RepoPath $paths.keli_native_client -LockFiles @('Cargo.lock')
}

if ($IncludeLegacyKelinode) {
    $legacyPath = Resolve-RepoPath -Name 'kelinode'
    $legacyLocks = @()
    if (Test-Path -LiteralPath (Join-Path $legacyPath 'go.sum')) {
        $legacyLocks = @('go.sum')
    }
    $repositories['legacy_kelinode'] = Get-RepositoryInfo -Name 'kelinode' -RepoPath $legacyPath -LockFiles $legacyLocks
} else {
    $repositories['legacy_kelinode'] = $null
}

$panelContract = Get-Content -Raw -LiteralPath (Join-Path $paths.keliboard 'contracts/node-api/node-api.json') | ConvertFrom-Json
$nodeContractSource = Get-Content -Raw -LiteralPath (Join-Path $paths.kelinode_rs 'src/panel/contract.rs')
$nodeContractMatch = [regex]::Match($nodeContractSource, 'NODE_API_CONTRACT_VERSION:\s*&str\s*=\s*"([^"]+)"')
if (-not $nodeContractMatch.Success) {
    throw 'kelinode-rs node API contract version was not found'
}
$panelContractVersion = [string]$panelContract.version
$nodeContractVersion = $nodeContractMatch.Groups[1].Value
$contractsMatch = $panelContractVersion -eq $nodeContractVersion
if (-not $contractsMatch) {
    throw "node API contract mismatch: panel=$panelContractVersion, kelinode-rs=$nodeContractVersion"
}

$artifacts = [ordered]@{
    admin_bundle = Get-AdminBundleArtifact -KeliboardPath $paths.keliboard -AdminRepository $repositories.keli_admin
    user_theme = Get-UserThemeArtifact -UserRepository $repositories.keli_user -ThemePath $UserThemePath
    kelinode_rs = Get-KelinodeRsArtifact -NodeRepository $repositories.kelinode_rs -CoreRepository $repositories.keli_core_rs -ManifestPath $KelinodeRsManifestPath
    native_client = Get-NativeClientArtifact -ClientRepository $repositories.keli_native_client -ManifestPath $NativeClientManifestPath -ArtifactPath $NativeClientArtifactPath
}

$allClean = @($repositories.Values | Where-Object { $null -ne $_ -and $_.dirty }).Count -eq 0
$artifactStatuses = @($artifacts.Values | ForEach-Object { [string]$_.status })
$allArtifactsPassed = @($artifactStatuses | Where-Object { $_ -ne 'passed' }).Count -eq 0
$artifactGate = $(if ($allArtifactsPassed) { 'passed' } elseif (@($artifactStatuses | Where-Object { $_ -eq 'failed' }).Count -gt 0) { 'failed' } else { 'skipped' })

$manifest = [ordered]@{
    schema_version = 1
    release_version = $ReleaseVersion
    generated_at = (Get-Date).ToUniversalTime().ToString('o')
    repositories = $repositories
    compatibility = [ordered]@{
        node_api = [ordered]@{
            panel = $panelContractVersion
            kelinode_rs = $nodeContractVersion
            match = [bool]$contractsMatch
        }
        embedded_core = [ordered]@{
            kelinode_rs_git_sha = [string]$repositories.kelinode_rs.git_sha
            keli_core_rs_git_sha = [string]$repositories.keli_core_rs.git_sha
        }
    }
    artifacts = $artifacts
    gates = [ordered]@{
        strict = [bool]$Strict
        source_clean = $(if ($allClean) { 'passed' } else { 'failed' })
        contracts = 'passed'
        artifacts = $artifactGate
    }
}

$outputDirectory = Split-Path -Parent $OutputPath
if ($outputDirectory -ne '' -and -not (Test-Path -LiteralPath $outputDirectory)) {
    New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
}
$manifest | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $OutputPath -Encoding UTF8

$phpExecutable = ''
if (-not [string]::IsNullOrWhiteSpace($PhpPath)) {
    if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw "PHP executable not found: $PhpPath"
    }
    $phpExecutable = (Resolve-Path -LiteralPath $PhpPath).Path
} elseif (-not [string]::IsNullOrWhiteSpace($env:KELI_PHP)) {
    if (-not (Test-Path -LiteralPath $env:KELI_PHP -PathType Leaf)) {
        throw "KELI_PHP executable not found: $($env:KELI_PHP)"
    }
    $phpExecutable = (Resolve-Path -LiteralPath $env:KELI_PHP).Path
} else {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $php) {
        $phpExecutable = $php.Source
    }
}

if ($phpExecutable -ne '') {
    $verifyArguments = @((Join-Path $paths.keliboard 'scripts/verify-release-manifest.php'))
    if ($Strict) {
        $verifyArguments += '--strict'
    }
    $verifyArguments += "--workspace-root=$WorkspaceRoot"
    $verifyArguments += $OutputPath
    $verifyOutput = & $phpExecutable @verifyArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'release manifest verification failed'
    }
    if ($verifyOutput) {
        $verifyOutput | Write-Host
    }
} elseif ($Strict) {
    throw 'php is required for strict release manifest verification'
}

Write-Output $OutputPath
