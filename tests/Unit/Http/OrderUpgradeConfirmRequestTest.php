<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\User\OrderUpgradeConfirm;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class OrderUpgradeConfirmRequestTest extends TestCase
{
    public function test_selection_is_optional_for_legacy_clients_but_must_be_complete_and_valid_when_sent(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $rules = (new OrderUpgradeConfirm())->rules();
        foreach ([[], ['target_plan_id' => 3, 'period' => 'month_price'], ['target_plan_id' => 3, 'period' => 'yearly']] as $selection) {
            $this->assertTrue($factory->make(['quote_token' => 'local-quote'] + $selection, $rules)->passes());
        }
        foreach ([['target_plan_id' => 3], ['period' => 'year_price'], ['target_plan_id' => -1, 'period' => 'monthly'],
            ['target_plan_id' => 3, 'period' => 'invalid'], ['target_plan_id' => 'not-an-id', 'period' => 'monthly']] as $selection) {
            $this->assertTrue($factory->make(['quote_token' => 'local-quote'] + $selection, $rules)->fails());
        }
    }
}
