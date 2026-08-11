<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TicketAiQualityService;
use PHPUnit\Framework\TestCase;

final class TicketAiQualityServiceTest extends TestCase
{
    public function test_exact_and_small_edits_are_classified(): void
    {
        $service = new TicketAiQualityService();

        $exact = $service->compare('请重新导入订阅。', '请重新导入订阅。');
        $this->assertSame(0.0, $exact['edit_ratio']);
        $this->assertSame(TicketAiQualityService::RATING_EXACT, $exact['quality_rating']);

        $minor = $service->compare('请重新导入订阅。', '请重新导入一下订阅。');
        $this->assertGreaterThan(0, $minor['edit_ratio']);
        $this->assertLessThanOrEqual(0.20, $minor['edit_ratio']);
        $this->assertSame(TicketAiQualityService::RATING_MINOR_EDIT, $minor['quality_rating']);
    }

    public function test_material_rewrite_is_classified_without_storing_content(): void
    {
        $result = (new TicketAiQualityService())->compare(
            '请稍后重试。',
            '我们已核对订单，需要您提供支付平台的交易号后再继续处理。'
        );

        $this->assertGreaterThan(0.20, $result['edit_ratio']);
        $this->assertSame(TicketAiQualityService::RATING_MAJOR_EDIT, $result['quality_rating']);
        $this->assertArrayNotHasKey('draft', $result);
        $this->assertArrayNotHasKey('final_message', $result);
    }
}
