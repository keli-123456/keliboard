<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\PlanController;
use App\Http\Controllers\V1\User\ServerController;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use App\Support\ProtocolCapabilityService;
use App\Support\UserClientCompatibilityService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserControllerNullRegressionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
    }

    public function test_user_plan_fetch_returns_business_error_when_user_was_deleted(): void
    {
        $response = (new PlanController(new PlanService(new Plan())))->fetch($this->requestForMissingUser());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('The user does not exist', $response->getData(true)['message']);
    }

    public function test_user_server_fetch_returns_business_error_when_user_was_deleted(): void
    {
        $compatibility = new UserClientCompatibilityService(new ProtocolCapabilityService());
        $response = (new ServerController())->fetch($this->requestForMissingUser(), $compatibility);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('The user does not exist', $response->getData(true)['message']);
    }

    public function test_invite_fetch_returns_business_error_when_user_was_deleted(): void
    {
        $response = (new InviteController())->fetch($this->requestForMissingUser());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('The user does not exist', $response->getData(true)['message']);
    }

    private function requestForMissingUser(): Request
    {
        $user = new User();
        $user->id = 999;

        $request = Request::create('/api/v1/user/test', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
