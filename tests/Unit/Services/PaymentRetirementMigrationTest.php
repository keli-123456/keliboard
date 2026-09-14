<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentRetirementMigrationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    public function test_upgrade_preserves_channels_and_rollback_does_not_enable_archived_channel(): void
    {
        $this->setUpInMemoryDatabase();
        $this->createPaymentTable();
        $this->database->schema()->table('v2_payment', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
        $id = DB::table('v2_payment')->insertGetId(['uuid' => 'channel', 'name' => 'Pay', 'payment' => 'EPay', 'enable' => true]);
        $migration = require base_path('database/migrations/2026_09_14_180000_preserve_deleted_payment_channels.php');
        $migration->up();
        $channel = Payment::findOrFail($id);
        $this->assertTrue($channel->enable);
        $channel->delete();
        $this->assertNull(Payment::find($id));
        $this->assertSame($id, Payment::withTrashed()->firstOrFail()->id);
        $migration->down();
        $this->assertSame(0, (int) DB::table('v2_payment')->where('id', $id)->value('enable'));
    }
}
