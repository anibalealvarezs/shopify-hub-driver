<?php

declare(strict_types=1);
namespace Tests\Unit;

use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver;
use PHPUnit\Framework\TestCase;

final class ShopifyGraphqlSyncTest extends TestCase
{
    public function testHistoricalBulkCustomersUseSameNormalization(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->expects(self::never())->method('graphqlPaginate');
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi', 'streamBulkQuery'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $driver->expects(self::once())->method('streamBulkQuery')->willReturnCallback(static function ($api, $query, $callback): void {
            self::assertStringContainsString('{ customers { edges { node {', $query);
            self::assertStringNotContainsString('$cursor', $query);
            $callback(['id' => 'customer', 'email' => null]);
        });
        $received = [];
        $driver->setDataProcessor(static function ($records, $type) use (&$received): void { $received[$type] = $records->first(); });
        $driver->syncShopAndCustomers(['transport' => 'bulk']);
        self::assertSame('customer', $received['customer']->platformId);
    }

    public function testMetricsSplitAtCalendarQuarterBoundaries(): void
    {
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['syncDailySales'])->getMock();
        $ranges = [];
        $driver->method('syncDailySales')->willReturnCallback(static function ($start, $end) use (&$ranges) {
            $ranges[] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
            return new \Symfony\Component\HttpFoundation\Response('ok');
        });
        $driver->syncMetrics(new \DateTime('2026-03-15'), new \DateTime('2026-07-02'));
        self::assertSame([['2026-03-15', '2026-03-31'], ['2026-04-01', '2026-06-30'], ['2026-07-01', '2026-07-02']], $ranges);
    }
    public function testDispatchesShopBeforeCustomerPagesWithSourceIdentity(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => [
            'id' => 'gid://shopify/Shop/1', 'name' => 'Example', 'myshopifyDomain' => 'example.myshopify.com',
            'primaryDomain' => ['host' => 'example.com'], 'currencyCode' => 'USD',
        ]]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback): void {
            self::assertSame('customers', $connection);
            $callback(['nodes' => [['id' => 'gid://shopify/Customer/7', 'legacyResourceId' => '7', 'email' => 'test@example.test']]]);
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $received = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$received): void { $received[] = [$type, $items->first()]; });
        $driver->syncShopAndCustomers();
        self::assertSame(['account', 'store', 'customer'], array_column($received, 0));
        self::assertSame('gid://shopify/Shop/1', $received[1][1]->data['owner_platform_id']);
        self::assertSame(['example.myshopify.com', 'example.com'], $received[1][1]->data['match_domains']);
        self::assertSame('gid://shopify/Customer/7', $received[2][1]->platformId);
        self::assertSame('7', $received[2][1]->data['legacyResourceId']);
    }

    public function testCancellationFailsBeforeRequest(): void
    {
        $driver = new ShopifyDriver();
        $driver->setDataProcessor(static function (): void {});
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancelled');
        $driver->syncShopAndCustomers([], static fn (): bool => false);
    }
    public function testCustomerWithoutEmailIsDispatchedWithSourceIdentity(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback): void {
            $callback(['nodes' => [['id' => 'customer', 'email' => null]]]);
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $received = [];
        $driver->setDataProcessor(static function ($collection, $type) use (&$received): void { $received[$type] = $collection->first(); });
        $driver->syncShopAndCustomers();
        self::assertSame('customer', $received['customer']->platformId);
        self::assertNull($received['customer']->identity_hash);
    }
    public function testProductSyncCollectsAllVariantPagesBeforeDispatch(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop', 'myshopifyDomain' => 'example.myshopify.com']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback, $variables): void {
            if ($connection === 'products') {
                $callback(['nodes' => [['id' => 'product', 'legacyResourceId' => '1', 'title' => 'Example']]]);
                return;
            }
            if ($connection === 'product.collections') { $callback(['nodes' => [['id' => 'collection', 'title' => 'Catalog']]]); return; }
            self::assertSame('product.variants', $connection);
            self::assertSame('product', $variables['id']);
            $callback(['nodes' => [['id' => 'variant1', 'sku' => 'one']]]);
            $callback(['nodes' => [['id' => 'variant2', 'sku' => 'two']]]);
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $received = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$received): void { $received[] = [$type, $items->first()]; });
        $driver->syncShopAndProducts();
        self::assertSame(['account', 'store', 'product'], array_column($received, 0));
        self::assertSame(['variant1', 'variant2'], array_map(static fn ($variant) => $variant->platformId, $received[2][1]->variants));
        self::assertSame('shop', $received[2][1]->storePlatformId);
    }

    public function testProductSyncDoesNotDispatchIncompleteVariants(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback): void {
            if ($connection === 'products') { $callback(['nodes' => [['id' => 'product']]]); return; }
            throw new \UnexpectedValueException('Repeated cursor');
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $types = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$types): void { $types[] = $type; });
        try {
            $driver->syncShopAndProducts();
            self::fail('Expected pagination error');
        } catch (\UnexpectedValueException $e) {
            self::assertSame(['account', 'store'], $types);
        }
    }
    public function testOrderSyncCollectsAllLinePagesBeforeDispatch(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback, $variables): void {
            if ($connection === 'orders') { $callback(['nodes' => [['id' => 'order', 'createdAt' => '2026-01-01T00:00:00Z', 'customer' => ['id' => 'customer']]]]); return; }
            if ($connection === 'order.discountApplications') {
                $callback(['nodes' => [['__typename' => 'AutomaticDiscountApplication', 'index' => 0, 'title' => 'Sale']]]);
                $callback(['nodes' => [['__typename' => 'DiscountCodeApplication', 'index' => 1, 'code' => 'VIP']]]);
                return;
            }
            self::assertSame('order.lineItems', $connection);
            self::assertSame('order', $variables['id']);
            $callback(['nodes' => [['id' => 'line1', 'product' => ['id' => 'product']]]]);
            $callback(['nodes' => [['id' => 'line2', 'product' => ['id' => 'product2']]]]);
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $received = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$received): void { $received[] = [$type, $items->first()]; });
        $driver->syncShopAndOrders();
        self::assertSame(['account', 'store', 'order', 'event'], array_column($received, 0));
        self::assertSame(['line1', 'line2'], array_column($received[2][1]->lineItems, 'id'));
        self::assertSame(['Sale', null], array_map(static fn ($application) => $application['title'] ?? null, $received[2][1]->data['discountApplications']['nodes']));
        self::assertSame([0, 1], array_column($received[2][1]->data['discountApplications']['nodes'], 'index'));
        self::assertSame('shop', $received[2][1]->storePlatformId);
    }

    public function testOrderSyncDoesNotDispatchPartialLines(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback): void {
            if ($connection === 'orders') { $callback(['nodes' => [['id' => 'order']]]); return; }
            throw new \UnexpectedValueException('Repeated cursor');
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $types = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$types): void { $types[] = $type; });
        try { $driver->syncShopAndOrders(); self::fail('Expected pagination failure'); }
        catch (\UnexpectedValueException $e) { self::assertSame(['account', 'store'], $types); }
    }
    public function testOrderSyncDoesNotDispatchPartialDiscountApplications(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback): void {
            if ($connection === 'orders') { $callback(['nodes' => [['id' => 'order']]]); return; }
            if ($connection === 'order.lineItems') { $callback(['nodes' => []]); return; }
            $callback(['nodes' => [['index' => 0, '__typename' => 'AutomaticDiscountApplication']]]);
            throw new \UnexpectedValueException('Application pagination failed');
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $types = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$types): void { $types[] = $type; });
        try { $driver->syncShopAndOrders(); self::fail('Expected pagination failure'); }
        catch (\UnexpectedValueException $e) { self::assertSame(['account', 'store'], $types); }
    }
    public function testDiscountSyncCollectsAllCodesBeforeDispatch(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback, $variables): void {
            if ($connection === 'discountNodes') {
                $callback(['nodes' => [['id' => 'rule', 'discount' => ['__typename' => 'DiscountCodeBasic', 'title' => 'Sale']], ['id' => 'automatic', 'discount' => ['__typename' => 'DiscountAutomaticBasic', 'title' => 'Automatic']]]]);
                return;
            }
            self::assertSame('discountNode.discount.codes', $connection);
            self::assertSame('rule', $variables['id']);
            $callback(['nodes' => [['id' => 'code-1', 'code' => 'SALE']]]);
            $callback(['nodes' => [['id' => 'code-2', 'code' => 'VIP']]]);
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $received = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$received): void { $received[] = [$type, $items->first()]; });
        $driver->syncShopAndDiscounts();
        self::assertSame(['account', 'store', 'price_rule', 'price_rule'], array_column($received, 0));
        self::assertSame('rule', $received[2][1]->platformId);
        self::assertSame(['code-1', 'code-2'], array_column($received[2][1]->data['codes'], 'identity'));
        self::assertSame('automatic', $received[3][1]->platformId);
        self::assertSame([], $received[3][1]->data['codes']);
    }
    public function testDiscountSyncDoesNotDispatchPartialCodes(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop']]]);
        $api->method('graphqlPaginate')->willReturnCallback(static function ($query, $connection, $callback): void {
            if ($connection === 'discountNodes') { $callback(['nodes' => [['id' => 'rule', 'discount' => ['__typename' => 'DiscountCodeBasic']]]]); return; }
            throw new \UnexpectedValueException('Pagination failed');
        });
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $types = [];
        $driver->setDataProcessor(static function ($items, $type) use (&$types): void { $types[] = $type; });
        try { $driver->syncShopAndDiscounts(); self::fail('Expected pagination failure'); }
        catch (\UnexpectedValueException $e) { self::assertSame(['account', 'store'], $types); }
    }
    public function testCommerceSyncOrdersDependenciesAndStopsOnFailure(): void
    {
        $methods = ['syncShopAndCustomers', 'syncShopAndCollections', 'syncShopAndProducts', 'syncShopAndDiscounts', 'syncShopAndOrders'];
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods($methods)->getMock();
        $calls = [];
        foreach ($methods as $method) {
            $driver->method($method)->willReturnCallback(static function () use (&$calls, $method) {
                $calls[] = $method;
                if ($method === 'syncShopAndDiscounts') throw new \RuntimeException('discount failure');
                return new \Symfony\Component\HttpFoundation\Response('ok');
            });
        }
        try { $driver->syncShopCommerce(); self::fail('Expected discount failure'); }
        catch (\RuntimeException $e) {
            self::assertSame('discount failure', $e->getMessage());
            self::assertSame(array_slice($methods, 0, 4), $calls);
        }
    }
    public function testCommerceSyncCompletesInDependencyOrder(): void
    {
        $methods = ['syncShopAndCustomers', 'syncShopAndCollections', 'syncShopAndProducts', 'syncShopAndDiscounts', 'syncShopAndOrders'];
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods($methods)->getMock();
        $calls = [];
        foreach ($methods as $method) {
            $driver->method($method)->willReturnCallback(static function () use (&$calls, $method) {
                $calls[] = $method;
                return new \Symfony\Component\HttpFoundation\Response('ok');
            });
        }
        $driver->syncShopCommerce();
        self::assertSame($methods, $calls);
    }
    /** @dataProvider workerEntityModes */
    public function testWorkerDispatchesExplicitGraphqlModes(string $mode, string $method): void
    {
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods([$method])->getMock();
        $driver->expects(self::once())->method($method)->willReturn(new \Symfony\Component\HttpFoundation\Response('ok'));
        self::assertSame('ok', $driver->sync(new \DateTime('2026-01-01'), new \DateTime('2026-01-02'), ['entity' => $mode])->getContent());
    }

    public static function workerEntityModes(): array
    {
        return [['shop_customers', 'syncShopAndCustomers'], ['shop_products', 'syncShopAndProducts'], ['shop_orders', 'syncShopAndOrders'], ['shop_discounts', 'syncShopAndDiscounts'], ['shop_commerce', 'syncShopCommerce']];
    }

    public function testCompleteEntityJobCannotPretendToSucceed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataProcessor not set');
        (new ShopifyDriver())->sync(new \DateTime('2026-01-01'), new \DateTime('2026-01-02'), ['entity' => 'entities']);
    }
    public function testDailySalesDispatchesAccountThenDailyMetrics(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'shop', 'currencyCode' => 'USD']]]);
        $api->expects(self::once())->method('shopifyqlQuery')->with(self::callback(static fn ($q) => str_contains($q, 'SINCE 2026-01-01 UNTIL 2026-01-02')))->willReturn([
            'rows' => [['day' => '2026-01-01', 'orders' => 1, 'gross_sales' => '5', 'net_sales' => '5', 'total_sales' => '5']],
        ]);
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $calls = [];
        $driver->setDataProcessor(static function ($items, $type = null) use (&$calls): void { $calls[] = [$type, $items]; });
        $driver->sync(new \DateTime('2026-01-01'), new \DateTime('2026-01-02'), ['entity' => 'shop_daily_sales']);
        self::assertSame(['account', 'store', null], array_column($calls, 0));
        self::assertCount(4, $calls[2][1]);
        self::assertSame('shop', $calls[2][1]->first()->channeledAccountPlatformId);
    }

    public function testDailySalesRejectsTooLongRange(): void
    {
        $driver = new ShopifyDriver();
        $driver->setDataProcessor(static function (): void {});
        $this->expectException(\InvalidArgumentException::class);
        $driver->syncDailySales(new \DateTime('2025-01-01'), new \DateTime('2027-01-01'));
    }
}
