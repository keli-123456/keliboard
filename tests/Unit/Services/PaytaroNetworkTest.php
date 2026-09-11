<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PaytaroNetwork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaytaroNetworkTest extends TestCase
{
    #[DataProvider('metadata')]
    public function test_only_explicit_network_names_can_fill_missing_channel_types(array $method, string $currency, ?string $expected): void
    {
        $this->assertSame($expected, PaytaroNetwork::fromMethod($method, $currency));
    }

    public static function metadata(): array
    {
        return [
            [['name' => 'USDC BEP20'], 'USDC', 'BNB SMART CHAIN (BEP20)'],
            [['name' => 'usdc-polygon'], 'USDC', 'POLYGON'],
            [['name' => 'USDC Solana', 'type' => null], 'USDC', 'SOLANA'],
            [['name' => 'USDC', 'type' => 'SOLANA:MAINNET:Token'], 'USDC', 'SOLANA MAINNET'],
            [['name' => 'USDC Binance'], 'USDC', null],
            [['name' => 'USDT BEP20'], 'USDC', null],
            [['name' => 'USDC TRC20/Solana'], 'USDC', null],
            [['name' => 'USDC Solana TESTNET'], 'USDC', null],
            [['name' => 'USDC Solana', 'type' => 'bad:network'], 'USDC', null],
            [['name' => 'USDC Solana', 'type' => false], 'USDC', null],
            [['name' => 'SOL'], 'SOL', null],
            [['name' => 'BNB', 'address' => '0xTest'], 'BNB', null],
            [['name' => 'TRX', 'address' => 'TTest'], 'TRX', null],
        ];
    }
}
