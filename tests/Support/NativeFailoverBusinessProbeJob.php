<?php
declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class NativeFailoverBusinessProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 5;
    public function __construct(public readonly string $tag) { $this->onConnection('redis'); $this->onQueue('admin_operations'); }
    public function handle(): void
    {
        NativeFailoverHorizonFixture::configuration(getenv(), NativeFailoverConcurrentFixture::$owner);
        NativeFailoverConcurrentFixture::assertOwner();
        if (!$this->job || $this->job->getConnectionName() !== 'redis' || $this->job->getQueue() !== 'admin_operations')
            throw new \RuntimeException('Business probe consumed by the wrong pool.');
        NativeFailoverConcurrentFixture::event('business_processed', $this->tag);
    }
}
