[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$releaseScript = Join-Path $PSScriptRoot 'release-full-stack.ps1'
$fixtureRoot = Join-Path $repoRoot 'build\release-full-stack-tests\workspace'

Remove-Item -LiteralPath $fixtureRoot -Recurse -Force -ErrorAction SilentlyContinue
foreach ($repoName in @('keliboard', 'keli-admin', 'keli-user', 'kelinode-rs', 'keli-core-rs', 'keli-native-client')) {
    New-Item -ItemType Directory -Force -Path (Join-Path $fixtureRoot "$repoName\.git") | Out-Null
}

$planOutput = & $releaseScript `
    -ReleaseVersion 'v9.8.7-contract-test' `
    -WorkspaceRoot $fixtureRoot `
    -PlanOnly
$plan = $planOutput -join "`n"
foreach ($expected in @(
    'mode Prepare',
    'source keliboard keli-admin keli-user kelinode-rs keli-core-rs keli-native-client',
    'prepare test build sync package generate-candidate-manifest',
    'verify require-clean-source require-four-artifacts verify-contracts verify-sha256',
    'publish verify tag-six-repositories push-optional'
)) {
    if (!$plan.Contains($expected)) {
        throw "full-stack release plan is missing: $expected"
    }
}

$source = Get-Content -Raw -LiteralPath $releaseScript
foreach ($expected in @(
    "@('run', 'verify:reproducible')",
    "@('scripts/verify-release-manifest.tests.php')",
    "@('test', '--locked', '--all-targets', '--', '--test-threads=1')",
    'scripts/tauri-client-update-manifest.tests.ps1',
    'scripts/desktop-package.tests.ps1',
    'Ensure-ReleaseTag',
    'Invoke-StrictPreflight'
)) {
    if (!$source.Contains($expected)) {
        throw "full-stack release implementation is missing: $expected"
    }
}

Write-Output 'full-stack release contract tests passed'
