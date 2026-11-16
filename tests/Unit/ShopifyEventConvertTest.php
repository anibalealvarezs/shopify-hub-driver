<?php
declare(strict_types=1);
namespace Tests\Unit;
use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyEventConvert;
use PHPUnit\Framework\TestCase;
final class ShopifyEventConvertTest extends TestCase
{
    public function testEventsPreserveOrderAndRefundIdentitiesOnReplay(): void
    {
        $order = ['id' => 'gid://shopify/Order/1', 'createdAt' => '2026-01-01T00:00:00Z', 'refunds' => [['id' => 'gid://shopify/Refund/2', 'createdAt' => '2026-01-02T00:00:00Z']]];
        $first = ShopifyEventConvert::order($order, 'shop')->toArray();
        $second = ShopifyEventConvert::order($order, 'shop')->toArray();
        self::assertEquals($first, $second);
        self::assertSame(['order_placed', 'order_refunded'], array_column($first, 'name'));
        self::assertSame('gid://shopify/Refund/2', $first[1]->platformId);
        self::assertSame('gid://shopify/Order/1', $first[1]->data['order_id']);
        self::assertFalse($first[1]->data['financial_status_verified']);
    }
}
