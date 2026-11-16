<?php

declare(strict_types=1);
namespace Tests\Unit;

use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyMetricConvert;
use PHPUnit\Framework\TestCase;

final class ShopifyMetricConvertTest extends TestCase
{
    public function testDailySalesKeepShopCurrencyAndRawMetricNames(): void
    {
        $rows = ['rows' => [['day' => '2026-01-01', 'orders' => 2, 'gross_sales' => '25.00', 'net_sales' => '20.00', 'total_sales' => null]]];
        $metrics = ShopifyMetricConvert::dailySales($rows, 'gid://shopify/Shop/1', 'USD', new \DateTime('2026-01-01'), new \DateTime('2026-01-02'));
        self::assertCount(3, $metrics);
        self::assertSame(['orders', 'gross_sales', 'net_sales'], array_map(static fn ($m) => $m->name, $metrics->toArray()));
        self::assertSame('gid://shopify/Shop/1', $metrics[0]->channeledAccountPlatformId);
        self::assertSame('USD', $metrics[0]->sourceCurrency);
        self::assertFalse($metrics[0]->isMonetary);
        self::assertTrue($metrics[1]->isMonetary);
        self::assertSame('2026-01-01', $metrics[0]->metricDate);
    }

    public function testDuplicateDaysAreRejected(): void
    {
        $row = ['day' => '2026-01-01', 'orders' => 0, 'gross_sales' => 0, 'net_sales' => 0, 'total_sales' => 0];
        $this->expectException(\UnexpectedValueException::class);
        ShopifyMetricConvert::dailySales(['rows' => [$row, $row]], 'shop', 'USD', new \DateTime('2026-01-01'), new \DateTime('2026-01-01'));
    }

    public function testInvalidMetricIsNotCoercedToZero(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ShopifyMetricConvert::dailySales(['rows' => [['day' => '2026-01-01', 'orders' => 'bad', 'gross_sales' => 0, 'net_sales' => 0, 'total_sales' => 0]]], 'shop', 'USD', new \DateTime('2026-01-01'), new \DateTime('2026-01-01'));
    }
}
