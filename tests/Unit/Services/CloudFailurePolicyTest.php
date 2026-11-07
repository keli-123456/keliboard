<?php
namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudFailurePolicy;
use App\Services\ServerMachine\Cloud\MachineCredentialRotationPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudFailurePolicyTest extends TestCase
{
    public static function classifications(): array
    {
        return [['linode', 'POST', 401, [], 'auth_invalid'], ['linode', 'POST', 403, [], 'permission_denied'],
            ['vultr', 'POST', 402, [], 'billing_locked'], ['digitalocean', 'POST', 429, [], 'rate_limited'],
            ['linode', 'POST', 422, ['errors' => [['reason' => 'Instance limit reached']]], 'quota_exhausted'],
            ['digitalocean', 'POST', 422, ['message' => 'Droplet limit exceeded'], 'quota_exhausted'],
            ['vultr', 'POST', 400, ['error' => 'Insufficient balance'], 'billing_locked'],
            ['linode', 'POST', 400, ['errors' => [['field' => 'image', 'reason' => 'Not available']]], 'template_invalid'],
            ['digitalocean', 'POST', 422, ['message' => 'Invalid image'], 'template_invalid'],
            ['vultr', 'POST', 400, ['error' => 'Insufficient capacity'], 'capacity_unavailable'],
            ['linode', 'POST', 422, ['errors' => [['reason' => 'balance: 12.00']]], 'request_rejected'],
            ['linode', 'POST', 400, ['errors' => [['reason' => 'Instance limit reached'], ['field' => 'image', 'reason' => 'Invalid image']]], 'request_rejected'],
            ['linode', 'POST', 400, ['errors' => ['reason' => 'Instance limit reached']], 'request_rejected'],
            ['linode', 'POST', 400, ['errors' => [['reason' => str_repeat('x', 2049) . 'Instance limit reached']]], 'request_rejected'],
            ['linode', 'POST', 503, ['errors' => [['reason' => 'Invalid token']]], 'outcome_unknown'],
            ['linode', 'POST', 408, [], 'outcome_unknown'], ['linode', 'POST', 409, [], 'outcome_unknown'],
            ['vultr', 'POST', 302, [], 'outcome_unknown'], ['linode', 'POST', 200, [], 'outcome_unknown'],
            ['linode', 'GET', 503, [], 'provider_unavailable'], ['vultr', 'GET', 200, [], 'response_invalid'],
            ['digitalocean', 'GET', 404, [], 'resource_missing']];
    }
    #[DataProvider('classifications')]
    public function test_classification_is_bounded_and_does_not_export_provider_text(string $provider, string $method, int $status, array $body, string $expected): void
    {
        $value = (new CloudFailurePolicy())->response($provider, $method, $status, $body);
        $this->assertSame($expected, $value['kind']); $this->assertArrayNotHasKey('body', $value); $this->assertArrayNotHasKey('reason', $value);
        if ($expected === 'outcome_unknown') { $this->assertSame('unknown', $value['outcome']); $this->assertFalse($value['rotation_candidate']); }
    }
    public function test_retry_headers_are_honored_without_immediate_rotation_or_unbounded_dates(): void
    {
        $policy = new CloudFailurePolicy(); $now = 1800000000;
        $this->assertSame(900, $policy->response('linode', 'POST', 429, [], ['Retry-After' => ['900']], $now)['cooldown_seconds']);
        $this->assertSame(600, $policy->response('vultr', 'POST', 429, [], ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', $now + 600)], $now)['cooldown_seconds']);
        $this->assertSame(400, $policy->response('digitalocean', 'GET', 429, [], ['RateLimit-Reset' => (string) ($now + 400)], $now)['cooldown_seconds']);
        $this->assertTrue($policy->response('vultr', 'POST', 429, [], ['Retry-After' => '99999999'], $now)['requires_review']);
        $this->assertSame(120, $policy->response('vultr', 'POST', 429, [], ['Retry-After' => '-1'], $now)['cooldown_seconds']);
        $this->assertSame('unknown', $policy->transport('linode', 'POST')['outcome']);
        $this->assertSame('provider_unavailable', $policy->transport('linode', 'GET')['kind']);
    }
    private function pool(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'revision' => 1, 'group_id' => 1, 'provider' => 'linode', 'credential_key' => hash('sha256', 'token:' . $id),
            'identity_key' => hash('sha256', 'account:' . ($id === 3 ? 2 : 1)), 'eligible' => true, 'budget_available' => true], [1, 2, 3]);
    }
    public function test_token_failure_can_select_a_different_token_but_account_failure_skips_all_aliases(): void
    {
        $pool = $this->pool(); $policy = new CloudFailurePolicy(); $planner = new MachineCredentialRotationPlanner();
        $auth = $planner->decide($policy->response('linode', 'POST', 401), $pool[0], $pool, 2, [1]);
        $this->assertSame(2, $auth['next_account_id']); $this->assertFalse($auth['dispatch_allowed']); $this->assertFalse($auth['automatic']);
        $billing = $planner->decide($policy->response('linode', 'POST', 402), $pool[0], $pool, 2, [1]);
        $this->assertSame(3, $billing['next_account_id']);
        $pool[0]['identity_key'] = null;
        $this->assertSame('account_identity_required', $planner->decide($policy->response('linode', 'POST', 402), $pool[0], $pool, 2, [1])['reason']);
    }
    public static function plannerStops(): array { return [['unknown'], ['rate'], ['template'], ['budget'], ['wrong_provider'], ['wrong_group'], ['duplicate_token'], ['already_tried'], ['unhealthy'], ['no_budget'], ['unverified_account']]; }
    #[DataProvider('plannerStops')]
    public function test_unsafe_rotation_is_rejected(string $case): void
    {
        $pool = $this->pool(); $policy = new CloudFailurePolicy(); $failure = $policy->response('linode', 'POST', 401); $remaining = 2; $tried = [1];
        if ($case === 'unknown') $failure = $policy->transport('linode', 'POST');
        if ($case === 'rate') $failure = $policy->response('linode', 'POST', 429);
        if ($case === 'template') $failure = $policy->response('linode', 'POST', 422, ['errors' => [['field' => 'image', 'reason' => 'invalid']]]);
        if ($case === 'budget') $remaining = 0;
        if ($case === 'already_tried') $tried = [1, 2, 3];
        foreach ([1, 2] as $index) {
            if ($case === 'wrong_provider') $pool[$index]['provider'] = 'vultr';
            if ($case === 'wrong_group') $pool[$index]['group_id'] = 2;
            if ($case === 'duplicate_token') $pool[$index]['credential_key'] = $pool[0]['credential_key'];
            if ($case === 'unhealthy') $pool[$index]['eligible'] = false;
            if ($case === 'no_budget') $pool[$index]['budget_available'] = false;
            if ($case === 'unverified_account') $pool[$index]['identity_key'] = null;
        }
        if ($case === 'unverified_account') $failure = $policy->response('linode', 'POST', 402);
        $value = (new MachineCredentialRotationPlanner())->decide($failure, $pool[0], $pool, $remaining, $tried);
        $this->assertNotSame('rotate', $value['action']); $this->assertNull($value['next_account_id']); $this->assertFalse($value['dispatch_allowed']);
    }
}
