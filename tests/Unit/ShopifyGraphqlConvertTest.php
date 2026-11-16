<?php

declare(strict_types=1);
namespace Tests\Unit;

use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyConvert;
use PHPUnit\Framework\TestCase;

final class ShopifyGraphqlConvertTest extends TestCase
{
    public function testShopOwnershipMatchesCommerceContract(): void
    {
        $shop = ['id' => 'gid://shopify/Shop/1', 'name' => 'Store', 'myshopifyDomain' => 'example.myshopify.com', 'currencyCode' => 'USD', 'primaryDomain' => ['host' => 'shop.example.com'], 'createdAt' => '2024-01-01T00:00:00Z'];
        $account = ShopifyConvert::shopAccount($shop)->first();
        $store = ShopifyConvert::shopStore($shop)->first();
        self::assertSame($shop['id'], $account->platformId);
        self::assertSame($shop['id'], $account->account_id);
        self::assertSame('store', $account->data['account_type']);
        self::assertSame($account->platformId, $store->data['owner_platform_id']);
        self::assertSame($account->account_id, $store->data['account_id']);
        self::assertSame('USD', $store->data['currency_code']);
        self::assertSame('shop.example.com', $store->data['domain']);
        self::assertSame(['example.myshopify.com', 'shop.example.com'], $store->data['match_domains']);
    }

    public function testCustomerIdentityAndStoreLink(): void
    {
        $customers = ShopifyConvert::graphqlCustomers([
            ['id' => 'gid://shopify/Customer/1', 'email' => ' Person@Example.COM ', 'createdAt' => '2024-02-01T00:00:00Z'],
            ['id' => 'gid://shopify/Customer/2', 'email' => null],
        ], 'gid://shopify/Shop/1');
        self::assertCount(2, $customers);
        self::assertSame(md5('person@example.com'), $customers[0]->identity_hash);
        self::assertSame('person@example.com', $customers[0]->email);
        self::assertNull($customers[1]->identity_hash);
        self::assertSame('gid://shopify/Shop/1', $customers[0]->storePlatformId);
        self::assertNotNull($customers[0]->platformCreatedAt);
    }

    public function testShopIdentityIsMandatory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShopifyConvert::shopAccount([]);
    }
    public function testCustomerWithoutProviderIdFails(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ShopifyConvert::graphqlCustomers([['email' => 'unknown@example.test']], 'shop-id');
    }
    public function testProductPreservesSourceAndVariantIds(): void
    {
        $products = ShopifyConvert::graphqlProducts([[
            'id' => 'gid://shopify/Product/1', 'legacyResourceId' => '1', 'title' => 'Product',
            'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/2', 'legacyResourceId' => '2', 'sku' => 'SKU']], 'pageInfo' => ['hasNextPage' => false]],
        ]], 'gid://shopify/Shop/1');
        $product = $products->first();
        self::assertSame('gid://shopify/Product/1', $product->platformId);
        self::assertSame('1', $product->data['legacyResourceId']);
        self::assertSame('gid://shopify/ProductVariant/2', $product->variants[0]->platformId);
        self::assertSame('2', $product->variants[0]->data['legacyResourceId']);
        self::assertSame('gid://shopify/Shop/1', $product->storePlatformId);
    }

    public function testIncompleteVariantsAreRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ShopifyConvert::graphqlProducts([['id' => 'product', 'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]], 'shop');
    }
    public function testOrderPreservesIdentifiersAttributionAndCurrency(): void
    {
        $order = ShopifyConvert::graphqlOrders([[
            'id' => 'gid://shopify/Order/1', 'legacyResourceId' => '1', 'createdAt' => '2024-01-01T00:00:00Z',
            'discountCodes' => ['SAVE10', 'VIP'],
            'customer' => ['id' => 'gid://shopify/Customer/2', 'email' => ' Buyer@Example.test '],
            'customerJourneySummary' => ['firstVisit' => ['landingPage' => 'https://example.test/', 'utmParameters' => ['source' => 'source']]],
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '25.50', 'currencyCode' => 'USD']],
            'lineItems' => ['nodes' => [['id' => 'gid://shopify/LineItem/3', 'quantity' => 2, 'product' => ['id' => 'gid://shopify/Product/4'], 'variant' => ['id' => 'gid://shopify/ProductVariant/5']]], 'pageInfo' => ['hasNextPage' => false]],
        ]], 'gid://shopify/Shop/6')->first();
        self::assertSame('gid://shopify/Order/1', $order->platformId);
        self::assertSame('1', $order->data['legacyResourceId']);
        self::assertSame([['code' => 'SAVE10'], ['code' => 'VIP']], $order->data['promos']);
        self::assertSame('gid://shopify/Customer/2', $order->customer->id);
        self::assertSame('buyer@example.test', $order->customer->email);
        self::assertSame('gid://shopify/Product/4', $order->lineItems[0]['product_id']);
        self::assertSame('gid://shopify/ProductVariant/5', $order->lineItems[0]['variant_id']);
        self::assertSame('source', $order->data['customerJourneySummary']['firstVisit']['utmParameters']['source']);
        self::assertSame(25.5, $order->total_amount);
        self::assertSame('USD', $order->currency);
        self::assertSame(md5('buyer@example.test'), $order->identity_hash);
    }

    public function testOrderRejectsTruncatedLines(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ShopifyConvert::graphqlOrders([['id' => 'order', 'lineItems' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]], 'shop');
    }

    public function testOrderRejectsInvalidDiscountCodes(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ShopifyConvert::graphqlOrders([['id' => 'order', 'discountCodes' => [null], 'lineItems' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]]], 'shop');
    }
    public function testOrderRejectsIncompleteDiscountApplications(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ShopifyConvert::graphqlOrders([['id' => 'order', 'lineItems' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]], 'discountApplications' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]], 'shop');
    }
}
