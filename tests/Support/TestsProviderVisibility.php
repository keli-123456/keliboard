<?php
namespace Tests\Support;

use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\MachineProvisionDiagnostic;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

trait TestsProviderVisibility
{
    public static function visibilityProviders(): array
    {
        return [['linode'], ['digitalocean'], ['vultr']];
    }

    private function visibilityStart(string $provider, ?int $status = 404): array
    {
        $this->nodeReadiness = true;
        $this->fixtures = [$this->fixture(2, $provider, true)];
        $this->duringInstanceRead = $status === null ? null : fn () => Http::response([], $status);
        return $this->advance($this->start());
    }

    private function visibilityPoll(MachineProvisionTask $task): MachineProvisionTask
    {
        $task->refresh()->fill(['next_check_at' => 0])->save();
        app(MachineProvisionService::class)->readback($task->id);
        return $task->refresh();
    }

    private function assertVisibilityHasNoExtraWrites(): void
    {
        $this->assertCount(1, $this->posts);
        $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(0, $this->candidateDeletes);
    }

    #[DataProvider('visibilityProviders')]
    public function test_provider_visibility_wait_resumes_original_instance_without_recreating(string $provider): void
    {
        $run = $this->visibilityStart($provider);
        $task = $this->task();
        $this->assertSame('waiting_provider', $task->status);
        $this->assertSame('resource_missing', $task->reason);
        $this->assertNotEmpty($task->external_id);
        $this->assertNotEmpty($task->provider_created_at);
        $this->assertGreaterThan(time(), $task->next_check_at);
        $identity = [$task->id, $task->external_id, $task->provider_created_at, $task->account_id,
            $task->marker, $task->dispatched_at, $task->deadline_at];
        $first = $task->evidence[MachineProvisionDiagnostic::KEY];
        for ($i = 0; $i < 2; $i++) {
            $this->visibilityPoll($task);
            $this->assertSame('waiting_provider', $task->status);
            $this->assertSame($identity, [$task->id, $task->external_id, $task->provider_created_at, $task->account_id,
                $task->marker, $task->dispatched_at, $task->deadline_at]);
            $this->assertSame($first, $task->evidence[MachineProvisionDiagnostic::KEY]);
            $this->assertVisibilityHasNoExtraWrites();
        }
        $this->duringInstanceRead = null;
        $this->visibilityPoll($task);
        $this->assertSame('waiting_initialization', $task->status);
        $this->assertNull($task->reason);
        $run = $this->ready($run);
        $this->assertSame('awaiting_dns', $run['members'][0]['phase']);
        $this->assertVisibilityHasNoExtraWrites();
        $run = $this->advance($run);
        $this->assertSame('completed', $run['status']);
        $this->assertCount(1, $this->posts);
        $this->assertSame(1, $this->dnsWrites);
        $this->assertSame($first, $task->fresh()->evidence[MachineProvisionDiagnostic::KEY]);
    }

    #[DataProvider('visibilityProviders')]
    public function test_provider_visibility_window_is_bounded_by_original_dispatch(string $provider): void
    {
        $this->visibilityStart($provider);
        $task = $this->task();
        $this->assertSame('waiting_provider', $task->status);
        $task->fill(['dispatched_at' => time() - 300])->save();
        $this->visibilityPoll($task);
        $this->assertSame('needs_review', $task->status);
        $this->assertSame('resource_missing', $task->reason);
        $this->visibilityPoll($task);
        $this->assertVisibilityHasNoExtraWrites();
    }

    #[DataProvider('visibilityProviders')]
    public function test_provider_visibility_does_not_hide_disappearance_after_startup(string $provider): void
    {
        $this->visibilityStart($provider, null);
        $task = $this->task();
        $this->assertSame('waiting_initialization', $task->status);
        $this->duringInstanceRead = fn () => Http::response([], 404);
        $this->visibilityPoll($task);
        $this->assertSame('needs_review', $task->status);
        $this->assertSame('resource_missing', $task->reason);
        $this->assertVisibilityHasNoExtraWrites();
    }

    #[DataProvider('visibilityProviders')]
    public function test_provider_visibility_reconcile_after_uncertain_post_never_posts_again(string $provider): void
    {
        $this->mode = 'unknown_present';
        $run = $this->visibilityStart($provider);
        $task = $this->task();
        $this->assertSame('outcome_unknown', $task->status);
        $this->assertNull($task->external_id);
        $task->fill(['next_check_at' => 0])->save();
        $run = $this->advance($run);
        $task->refresh();
        $this->assertSame('waiting_provider', $task->status);
        $this->assertNotEmpty($task->external_id);
        $this->assertVisibilityHasNoExtraWrites();
        $this->duringInstanceRead = null;
        $this->visibilityPoll($task);
        $this->assertSame('waiting_initialization', $task->status);
        $this->assertVisibilityHasNoExtraWrites();
    }

    public static function visibilityStops(): array
    {
        $cases = [];
        foreach (['linode', 'digitalocean', 'vultr'] as $provider) {
            foreach (['disabled', 'cancelled', 'deadline', 'bootstrap_failed', 'forbidden', 'invalid_response', 'identity_changed'] as $kind) {
                $cases[$provider . ' ' . $kind] = [$provider, $kind];
            }
        }
        return $cases;
    }

    #[DataProvider('visibilityStops')]
    public function test_provider_visibility_preserves_stop_error_and_identity_guards(string $provider, string $kind): void
    {
        $this->visibilityStart($provider);
        $task = $this->task();
        $this->assertSame('waiting_provider', $task->status);
        $first = $task->evidence[MachineProvisionDiagnostic::KEY];
        $reason = match ($kind) {
            'disabled' => 'provisioning_disabled', 'cancelled' => 'cancelled_by_admin', 'deadline' => 'reports_timeout',
            'bootstrap_failed' => 'initialization_failed', 'forbidden' => 'provider_forbidden',
            'invalid_response' => 'provider_invalid', 'identity_changed' => 'identity_changed',
        };
        if ($kind === 'disabled') config(['machine_provision.allow_live' => false]);
        elseif ($kind === 'cancelled') app(MachineProvisionService::class)->cancel($task->id, $this->admin->id);
        elseif ($kind === 'deadline') $task->fill(['deadline_at' => time()])->save();
        elseif ($kind === 'bootstrap_failed') {
            app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'failed');
            $this->duringInstanceRead = null;
        } elseif ($kind === 'forbidden') $this->duringInstanceRead = fn () => Http::response([], 403);
        elseif ($kind === 'invalid_response') $this->duringInstanceRead = fn () => Http::response('invalid-json', 200);
        else {
            $this->duringInstanceRead = null;
            $field = match ($provider) { 'digitalocean' => 'created_at', 'vultr' => 'date_created', default => 'created' };
            $this->remote[$task->external_id][$field] = '2020-01-01T00:00:00Z';
        }
        $this->visibilityPoll($task);
        $this->assertSame('needs_review', $task->status);
        $this->assertSame($reason, $task->reason);
        $this->assertSame($first, $task->evidence[MachineProvisionDiagnostic::KEY]);
        $this->assertVisibilityHasNoExtraWrites();
    }
}
