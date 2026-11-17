<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Http\Request;
use Keli\Acceptance\MemberCallbackRequest;
use PHPUnit\Framework\TestCase;

class MachineMemberCallbackRequestTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_callback_request.php';
    }

    private function request(string $path, string $method, ?array $body = null): Request
    {
        return Request::create($path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_KELI_INSTANCE_ID' => str_repeat('a', 32)], $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testPinnedAgentStartupAndReportQueriesKeepExactIdentity(): void
    {
        foreach (['/api/v2/server/config', '/api/v1/server/UniProxy/user',
            '/api/v1/server/UniProxy/user_delta', '/api/v1/server/UniProxy/alivelist',
            '/api/v2/server/traffic/batch'] as $path) {
            $request = $this->request($path . '?node_type=v2node&node_id=12&machine_id=7&token=fixture-only', 'GET');
            $this->assertSame(['kind' => 'report', 'machine_id' => 7, 'node_id' => 12,
                'installation_id' => str_repeat('a', 32)], MemberCallbackRequest::identify($request));
        }
        foreach (['/api/v2/server/report', '/api/v2/server/handshake', '/api/v1/server/UniProxy/push',
            '/api/v1/server/UniProxy/alive', '/api/v2/server/traffic/batch'] as $path) {
            $request = $this->request($path . '?node_type=v2node&node_id=12&machine_id=7&token=fixture-only', 'POST', []);
            $this->assertSame(12, MemberCallbackRequest::identify($request)['node_id']);
        }
        foreach (['nodes', 'status'] as $endpoint) {
            $request = $this->request('/api/v2/server/machine/' . $endpoint, 'POST', ['machine_id' => 7, 'token' => 'fixture-only']);
            $this->assertNull(MemberCallbackRequest::identify($request)['node_id']);
        }
    }

    public function testExistingProvisionAndStatusFormsRemainSupported(): void
    {
        $request = $this->request('/api/v2/server/status', 'POST',
            ['machine_id' => 7, 'node_id' => 12, 'token' => 'fixture-only', 'node_type' => 'v2node']);
        $this->assertSame(12, MemberCallbackRequest::identify($request)['node_id']);
        $request = $this->request('/api/v2/server/machine/provision/result', 'POST',
            ['task_id' => '11111111-1111-1111-1111-111111111111', 'installation_id' => str_repeat('b', 32)]);
        $this->assertSame('callback', MemberCallbackRequest::identify($request)['kind']);
    }

    public function testUnknownRoutesMethodsEncodedPathsAndIdentityConflictsAreDenied(): void
    {
        $query = '?node_type=v2node&node_id=12&machine_id=7&token=fixture-only';
        $cases = [
            ['/api/v2/admin/config' . $query, 'GET', null],
            ['/api/v2/server/config' . $query, 'POST', null],
            ['/api/v2/server/%63onfig' . $query, 'GET', null],
            ['/api/v2/server/config/' . $query, 'GET', null],
            ['/api/v2/server/config' . $query . '&url=https://elsewhere.invalid', 'GET', null],
            ['/api/v2/server/report' . $query, 'POST', ['machine_id' => 8]],
            ['/api/v2/server/report' . $query, 'POST', ['node_id' => 13]],
            ['/api/v2/server/report' . $query, 'POST', ['token' => 'different']],
            ['/api/v2/server/config' . $query, 'GET', ['status' => []]],
            ['/api/v2/server/report?machine_id=7&node_id=12&token=fixture-only&node_type=ss', 'POST', []],
            ['/api/v2/server/machine/nodes', 'POST', ['machine_id' => 7, 'node_id' => 12, 'token' => 'fixture-only']],
        ];
        foreach ($cases as [$path, $method, $body]) {
            try { MemberCallbackRequest::identify($this->request($path, $method, $body)); $this->fail('Unexpected admission'); }
            catch (\RuntimeException $error) { $this->assertSame('isolated_agent_request_denied', $error->getMessage()); }
        }
    }

    public function testMachineIdentityIsBoundedCanonicalAndNeverBooleanOrArray(): void
    {
        foreach ([null, true, false, 0, -1, 1.5, '01', '1e1', '2147483648', [7]] as $value) {
            $request = $this->request('/api/v2/server/machine/nodes', 'POST', ['machine_id' => $value, 'token' => 'fixture-only']);
            try { MemberCallbackRequest::identify($request); $this->fail('Malformed identity admitted'); }
            catch (\RuntimeException $error) { $this->assertSame('isolated_agent_request_denied', $error->getMessage()); }
        }
        $request = $this->request('/api/v2/server/machine/nodes', 'POST', ['machine_id' => 7, 'token' => 'fixture-only']);
        $request->headers->remove('X-Keli-Instance-Id');
        $this->expectExceptionMessage('isolated_agent_request_denied');
        MemberCallbackRequest::identify($request);
    }

    public function testOversizedBodyOrNonJsonFormCannotReachTheKernel(): void
    {
        foreach ([false, true] as $form) {
            $request = $this->request('/api/v2/server/machine/status', 'POST',
                ['machine_id' => 7, 'token' => 'fixture-only', 'padding' => $form ? '' : str_repeat('x', 8192)]);
            if ($form) $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');
            try { MemberCallbackRequest::identify($request); $this->fail('Unbounded request admitted'); }
            catch (\RuntimeException $error) { $this->assertSame('isolated_agent_request_denied', $error->getMessage()); }
        }
    }
}
