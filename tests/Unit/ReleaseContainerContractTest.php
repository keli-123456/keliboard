<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class ReleaseContainerContractTest extends TestCase
{
    public function test_dockerfile_builds_the_checked_out_source_with_the_lock_file(): void
    {
        $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
        $dockerignore = (string) file_get_contents(base_path('.dockerignore'));

        $this->assertStringContainsString('COPY . /www', $dockerfile);
        $this->assertStringContainsString('test -f composer.lock', $dockerfile);
        $this->assertStringNotContainsString('git clone', $dockerfile);
        $this->assertStringNotContainsString('composer.lock', $dockerignore);
        $this->assertStringContainsString('/.git', $dockerignore);
    }

    public function test_container_publish_waits_for_ci_and_checks_out_its_exact_sha(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/docker-publish.yml'));

        $this->assertStringContainsString('workflow_run:', $workflow);
        $this->assertStringContainsString('workflows: ["CI"]', $workflow);
        $this->assertStringContainsString("workflow_run.conclusion == 'success'", $workflow);
        $this->assertStringContainsString('SOURCE_SHA: ${{ github.event.workflow_run.head_sha || github.sha }}', $workflow);
        $this->assertStringContainsString('ref: ${{ env.SOURCE_SHA }}', $workflow);
        $this->assertStringNotContainsString('REPO_URL=', $workflow);
        $this->assertStringNotContainsString('BRANCH_NAME=', $workflow);
    }

    public function test_release_preflight_uses_the_admin_build_manifest_as_evidence(): void
    {
        $preflight = (string) file_get_contents(base_path('scripts/release-preflight.ps1'));

        $this->assertStringContainsString('build-manifest.json', $preflight);
        $this->assertStringContainsString('source_git_sha', $preflight);
        $this->assertStringContainsString('source_dirty', $preflight);
        $this->assertStringContainsString('Get-FileHash', $preflight);
        $this->assertStringNotContainsString("gitSha:\"([^\"]+)\"", $preflight);
        $this->assertStringNotContainsString("buildId:\"([^\"]+)\"", $preflight);
    }
}
