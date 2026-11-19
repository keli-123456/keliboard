<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverExampleController;
use App\Models\User;
use App\Services\ServerMachine\ExampleRehearsal\Grant;
use App\Services\ServerMachine\ExampleRehearsal\Wire;
use App\Services\ServerMachine\ExampleRehearsal\WorkerSocket;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineFailoverExampleTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private function configGrant(): array
    {
        return ['id' => str_repeat('a', 32), 'admin' => 42, 'opens' => time() - 10, 'expires' => time() + 120];
    }

    public static function denied(): array
    {
        return array_map(fn ($kind) => [$kind], ['anonymous', 'nonadmin', 'banned', 'other', 'disabled', 'future', 'expired', 'oversized', 'malformed']);
    }

    #[DataProvider('denied')]
    public function test_denied_requests_do_not_reach_worker(string $kind): void
    {
        $spec = $this->configGrant();
        $user = $this->user($kind !== 'nonadmin', $kind === 'banned', $kind === 'other' ? 41 : 42);
        $config = match ($kind) {
            'disabled' => [],
            'future' => array_replace($spec, ['opens' => time() + 30]),
            'expired' => array_replace($spec, ['opens' => time() - 60, 'expires' => time() - 1]),
            'oversized' => array_replace($spec, ['expires' => time() + 1801]),
            'malformed' => array_replace($spec, ['id' => 'not-a-grant']),
            default => $spec,
        };
        config(['failover_example' => $config]);
        $request = Request::create('/example');
        $request->setUserResolver(fn () => $kind === 'anonymous' ? null : $user);
        $stub = $this->createMock(WorkerSocket::class);
        $stub->expects($this->never())->method('send');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('');
        try { (new MachineFailoverExampleController())->status($request, $stub); }
        catch (HttpException $error) { $this->assertSame(404, $error->getStatusCode()); throw $error; }
    }

    public function test_only_the_fixed_json_actions_cross_the_gateway(): void
    {
        config(['failover_example' => $this->configGrant()]);
        $this->bindJsonResponseFactory();
        $controller = new MachineFailoverExampleController();
        $stub = $this->createMock(WorkerSocket::class);
        $stub->expects($this->once())->method('send')->with(
            $this->callback(fn (Grant $grant) => $grant->admin === 42), 'v2'
        )->willReturn(Wire::report('v2_passed', null, true, false));
        foreach ([
            ['/example', ['action' => 'v2', 'service_id' => 3]],
            ['/example', ['action' => 'execute_live']],
            ['/example?service_id=3', ['action' => 'v2']],
            ['/example', ['action' => ['v2']]],
        ] as [$uri, $body]) {
            try {
                $controller->operate($this->post($uri, $body), $stub);
                $this->fail('Unexpected request accepted');
            } catch (HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        }
        $response = $controller->operate($this->post('/example', ['action' => 'v2']), $stub);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertSame('v2_passed', $response->getData(true)['data']['phase']);
    }

    public function test_worker_errors_never_retry_and_wire_accepts_only_sanitized_synthetic_reports(): void
    {
        config(['failover_example' => $this->configGrant()]);
        $this->bindJsonResponseFactory();
        $stub = $this->createMock(WorkerSocket::class);
        $stub->expects($this->once())->method('send')->willReturn(['error' => 'unavailable']);
        $result = (new MachineFailoverExampleController())->operate($this->post('/example', ['action' => 'dns']), $stub);
        $this->assertSame(503, $result->getStatusCode());
        $valid = Wire::report('passed', null, true, true);
        $this->assertSame($valid, Wire::checked($valid));
        $this->assertNull(Wire::checked(array_replace($valid, ['live_operations' => 1])));
        $this->assertNull(Wire::checked($valid + ['secret' => 'fixture-only']));
        $this->assertNull(Wire::checked(array_replace($valid, ['phase' => 'ready'])));
        $active = Grant::parse($this->configGrant());
        $this->assertSame('v2', Wire::decode(Wire::request($active, 'v2'), $active));
        $this->assertNull(Wire::decode(Wire::request($active, 'save'), $active));
        $other = Grant::parse(array_replace($this->configGrant(), ['admin' => 84]));
        $this->assertNull(Wire::decode(Wire::request($other, 'v2'), $active));
    }

    private function post(string $uri, array $body): Request
    {
        $request = Request::create($uri, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn () => $this->user(true, false, 42));
        return $request;
    }

    private function user(bool $admin, bool $banned, int $id): User
    {
        return (new User())->forceFill(['id' => $id, 'is_admin' => $admin, 'banned' => $banned]);
    }
}
