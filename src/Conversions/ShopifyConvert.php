<?php

declare(strict_types=1);

namespace Anibalealvarezs\ShopifyHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Conversions\UniversalEntityConverter;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ShopifyConvert
 * 
 * Standardizes Shopify entity data (Customers, Orders, Products, etc.)
 * into APIs Hub objects using the UniversalEntityConverter.
 */
class ShopifyConvert
{
    /** Normalize the shop as the owning channeled account. */
    public static function shopAccount(array $shop): ArrayCollection
    {
        $id = (string) ($shop['id'] ?? '');
        if ($id === '') throw new \InvalidArgumentException('Shopify shop ID is required.');
        $shop['account_id'] = $id;
        $shop['account_type'] = 'store';
        return UniversalEntityConverter::convert([$shop], [
            'channel' => 'shopify', 'platform_id_field' => 'id', 'date_field' => 'createdAt',
            'mapping' => ['title' => 'name', 'account_id' => 'account_id'],
        ]);
    }

    /** The store is owned by the same shop account, as required by CommerceProcessor. */
    public static function shopStore(array $shop): ArrayCollection
    {
        $id = (string) ($shop['id'] ?? '');
        if ($id === '') throw new \InvalidArgumentException('Shopify shop ID is required.');
        $shop['account_id'] = $id;
        $shop['owner_platform_id'] = $id;
        $shop['domain'] = $shop['primaryDomain']['host'] ?? $shop['myshopifyDomain'] ?? null;
        $shop['match_domains'] = array_values(array_filter([$shop['myshopifyDomain'] ?? null, $shop['primaryDomain']['host'] ?? null], 'is_string'));
        $shop['currency_code'] = $shop['currencyCode'] ?? null;
        return UniversalEntityConverter::convert([$shop], [
            'channel' => 'shopify', 'platform_id_field' => 'id', 'date_field' => 'createdAt',
            'mapping' => ['title' => 'name', 'account_id' => 'account_id'],
        ]);
    }

    /** Normalize GraphQL customers without inventing an identity for missing email. */
    public static function graphqlCustomers(array $customers, string $shopId): ArrayCollection
    {
        if ($shopId === '') throw new \InvalidArgumentException('Shopify shop ID is required.');
        $rows = [];
        foreach ($customers as $customer) {
            if (!is_array($customer) || !isset($customer['id']) || !is_string($customer['id']) || trim($customer['id']) === '') {
                throw new \UnexpectedValueException('Shopify customer identity is missing.');
            }
            $email = trim((string) ($customer['email'] ?? ''));
            $customer['store_platform_id'] = $shopId;
            $customer['account_id'] = $shopId;
            $customer['identity_hash'] = $email !== '' ? md5(strtolower($email)) : null;
            $rows[] = $customer;
        }
        return UniversalEntityConverter::convert($rows, [
            'channel' => 'shopify', 'platform_id_field' => 'id', 'date_field' => 'createdAt',
            'mapping' => [
                'email' => static fn (array $r) => !empty(trim((string)($r['email'] ?? ''))) ? strtolower(trim((string)$r['email'])) : null, 'identity_hash' => 'identity_hash',
                'storePlatformId' => 'store_platform_id', 'account_id' => 'account_id',
            ],
        ]);
    }

    public static function graphqlCollections(array $collections, string $shopId): ArrayCollection
    {
        $rows = [];
        foreach ($collections as $collection) {
            if (!is_array($collection) || empty($collection['id']) || $shopId === '') throw new \UnexpectedValueException('Collection source identity and store are required.');
            $collection['store_platform_id'] = $shopId;
            $rows[] = $collection;
        }
        return UniversalEntityConverter::convert($rows, ['channel' => 'shopify', 'platform_id_field' => 'id', 'mapping' => ['storePlatformId' => 'store_platform_id']]);
    }

    /** Convert GraphQL products only when their variant connection is complete. */
    public static function graphqlProducts(array $products, string $shopId): ArrayCollection
    {
        if ($shopId === '') throw new \InvalidArgumentException('Shopify shop ID is required.');
        $rows = [];
        foreach ($products as $product) {
            if (!is_array($product) || !is_string($product['id'] ?? null) || trim($product['id']) === '') {
                throw new \UnexpectedValueException('Shopify product identity is missing.');
            }
            $variants = $product['variants'] ?? null;
            if (!is_array($variants) || !is_array($variants['nodes'] ?? null) || ($variants['pageInfo']['hasNextPage'] ?? null) !== false) {
                throw new \UnexpectedValueException('Shopify product variants are incomplete.');
            }
            $normalized = [];
            foreach ($variants['nodes'] as $variant) {
                if (!is_array($variant) || !is_string($variant['id'] ?? null) || trim($variant['id']) === '') {
                    throw new \UnexpectedValueException('Shopify variant identity is missing.');
                }
                $normalized[] = (object) [
                    'platformId' => $variant['id'], 'sku' => $variant['sku'] ?? '',
                    'platformCreatedAt' => isset($variant['createdAt']) ? new \DateTime($variant['createdAt']) : null,
                    'data' => $variant,
                ];
            }
            $product['store_platform_id'] = $shopId;
            $product['account_id'] = $shopId;
            $product['_normalized_variants'] = $normalized;
            $categories = [];
            if (isset($product['collections'])) {
                $connection = $product['collections'];
                if (!is_array($connection['nodes'] ?? null) || ($connection['pageInfo']['hasNextPage'] ?? null) !== false) throw new \UnexpectedValueException('Shopify product collections are incomplete.');
                foreach ($connection['nodes'] as $category) {
                    if (!is_array($category) || empty($category['id'])) throw new \UnexpectedValueException('Shopify collection identity is missing.');
                    $categories[] = (object)['platformId' => $category['id'], 'isSmartCollection' => isset($category['ruleSet']), 'data' => $category + ['store_platform_id' => $shopId]];
                }
            }
            $product['_normalized_categories'] = $categories;
            $rows[] = $product;
        }
        return UniversalEntityConverter::convert($rows, [
            'channel' => 'shopify', 'platform_id_field' => 'id', 'date_field' => 'createdAt',
            'mapping' => ['title' => 'title', 'storePlatformId' => 'store_platform_id', 'variants' => '_normalized_variants', 'categories' => '_normalized_categories'],
        ]);
    }

    /** Normalize a discount after all redeem-code pages have been collected. */
    public static function graphqlDiscounts(array $discounts, string $shopId): ArrayCollection
    {
        if ($shopId === '') throw new \InvalidArgumentException('Shopify shop ID is required.');
        $rows = [];
        foreach ($discounts as $discount) {
            if (!is_array($discount) || !is_string($discount['id'] ?? null) || trim($discount['id']) === '') {
                throw new \UnexpectedValueException('Shopify discount identity is missing.');
            }
            $connection = $discount['codes'] ?? null;
            if (!is_array($connection) || !is_array($connection['nodes'] ?? null) || ($connection['pageInfo']['hasNextPage'] ?? null) !== false) {
                throw new \UnexpectedValueException('Shopify discount codes are incomplete.');
            }
            $codes = [];
            foreach ($connection['nodes'] as $code) {
                if (!is_array($code) || !is_string($code['id'] ?? null) || trim($code['id']) === '' || !is_string($code['code'] ?? null) || trim($code['code']) === '') {
                    throw new \UnexpectedValueException('Shopify redeem code identity is missing.');
                }
                $code['identity'] = $code['id'];
                $codes[] = $code;
            }
            $discount['codes'] = $codes;
            $discount['store_platform_id'] = $shopId;
            $rows[] = $discount;
        }
        return UniversalEntityConverter::convert($rows, [
            'channel' => 'shopify', 'platform_id_field' => 'id', 'date_field' => 'createdAt',
            'mapping' => ['storePlatformId' => 'store_platform_id'],
        ]);
    }

    /** Normalize an order only after all line-item pages have been collected. */
    public static function graphqlOrders(array $orders, string $shopId): ArrayCollection
    {
        if ($shopId === '') throw new \InvalidArgumentException('Shopify shop ID is required.');
        $rows = [];
        foreach ($orders as $order) {
            if (!is_array($order) || !is_string($order['id'] ?? null) || trim($order['id']) === '') {
                throw new \UnexpectedValueException('Shopify order identity is missing.');
            }
            $connection = $order['lineItems'] ?? null;
            if (!is_array($connection) || !is_array($connection['nodes'] ?? null) || ($connection['pageInfo']['hasNextPage'] ?? null) !== false) {
                throw new \UnexpectedValueException('Shopify order line items are incomplete.');
            }
            $lines = [];
            foreach ($connection['nodes'] as $line) {
                if (!is_array($line) || !is_string($line['id'] ?? null) || trim($line['id']) === '') {
                    throw new \UnexpectedValueException('Shopify order line-item identity is missing.');
                }
                $line['product_id'] = $line['product']['id'] ?? null;
                $line['variant_id'] = $line['variant']['id'] ?? null;
                if (!isset($line['price']) && isset($line['originalUnitPriceSet']['shopMoney']['amount']) && is_numeric($line['originalUnitPriceSet']['shopMoney']['amount'])) {
                    $line['price'] = (float) $line['originalUnitPriceSet']['shopMoney']['amount'];
                }
                if (!isset($line['original_total']) && isset($line['originalTotalSet']['shopMoney']['amount']) && is_numeric($line['originalTotalSet']['shopMoney']['amount'])) {
                    $line['original_total'] = (float) $line['originalTotalSet']['shopMoney']['amount'];
                }
                if (!isset($line['discounted_total']) && isset($line['discountedTotalSet']['shopMoney']['amount']) && is_numeric($line['discountedTotalSet']['shopMoney']['amount'])) {
                    $line['discounted_total'] = (float) $line['discountedTotalSet']['shopMoney']['amount'];
                }
                $lines[] = $line;
            }
            $order['lines'] = $lines;
            if (array_key_exists('discountApplications', $order)) {
                $applications = $order['discountApplications'];
                if (!is_array($applications) || !is_array($applications['nodes'] ?? null) || ($applications['pageInfo']['hasNextPage'] ?? null) !== false) throw new \UnexpectedValueException('Shopify order discount applications are incomplete.');
                $seen = [];
                foreach ($applications['nodes'] as $application) {
                    if (!is_array($application) || !is_int($application['index'] ?? null) || $application['index'] < 0 || isset($seen[$application['index']]) || !is_string($application['__typename'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify discount application.');
                    $seen[$application['index']] = true;
                }
            }
            if (array_key_exists('discountCodes', $order)) {
                if (!is_array($order['discountCodes'])) throw new \UnexpectedValueException('Invalid Shopify order discount codes.');
                $order['promos'] = [];
                foreach ($order['discountCodes'] as $code) {
                    if (!is_string($code) || trim($code) === '') throw new \UnexpectedValueException('Invalid Shopify order discount code.');
                    $order['promos'][] = ['code' => $code];
                }
            }
            $order['store_platform_id'] = $shopId;
            $order['account_id'] = $shopId;
            $money = $order['currentTotalPriceSet']['shopMoney'] ?? [];
            $order['currency'] = $money['currencyCode'] ?? ($order['subtotalPriceSet']['shopMoney']['currencyCode'] ?? null);
            $order['total_amount'] = isset($money['amount']) && is_numeric($money['amount']) ? (float)$money['amount'] : null;
            $subtotalAmount = $order['subtotalPriceSet']['shopMoney']['amount'] ?? null;
            $order['subtotal_amount'] = $subtotalAmount !== null && is_numeric($subtotalAmount) ? (float)$subtotalAmount : null;
            $currentSubtotalAmount = $order['currentSubtotalPriceSet']['shopMoney']['amount'] ?? null;
            $order['current_subtotal_amount'] = $currentSubtotalAmount !== null && is_numeric($currentSubtotalAmount) ? (float)$currentSubtotalAmount : null;
            $discountsAmount = $order['totalDiscountsSet']['shopMoney']['amount'] ?? null;
            $order['discounts_amount'] = $discountsAmount !== null && is_numeric($discountsAmount) ? (float)$discountsAmount : null;
            $currentDiscountsAmount = $order['currentTotalDiscountsSet']['shopMoney']['amount'] ?? null;
            $order['current_discounts_amount'] = $currentDiscountsAmount !== null && is_numeric($currentDiscountsAmount) ? (float)$currentDiscountsAmount : null;
            $email = trim((string)($order['customer']['email'] ?? ''));
            $order['identity_hash'] = $email !== '' ? md5(strtolower($email)) : null;
            $rows[] = $order;
        }
        return UniversalEntityConverter::convert($rows, [
            'channel' => 'shopify', 'platform_id_field' => 'id', 'date_field' => 'createdAt',
            'mapping' => [
                'storePlatformId' => 'store_platform_id', 'account_id' => 'account_id',
                'customer' => static fn (array $r) => !empty($r['customer']['id']) ? (object) ['id' => $r['customer']['id'], 'email' => !empty(trim((string)($r['customer']['email'] ?? ''))) ? strtolower(trim((string)$r['customer']['email'])) : null] : null,
                'lineItems' => 'lines', 'identity_hash' => 'identity_hash',
                'currency' => 'currency', 'total_amount' => 'total_amount',
            ],
        ]);
    }

    public static function customers(array $customers): ArrayCollection
    {
        return UniversalEntityConverter::convert($customers, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'email' => fn ($r) => $r['email'] ?? '',
            ],
        ]);
    }

    public static function discounts(array $discounts): ArrayCollection
    {
        return UniversalEntityConverter::convert($discounts, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'code' => 'code',
            ],
        ]);
    }

    public static function priceRules(array $priceRules): ArrayCollection
    {
        return UniversalEntityConverter::convert($priceRules, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
        ]);
    }

    public static function orders(array $orders): ArrayCollection
    {
        return UniversalEntityConverter::convert($orders, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'customer' => fn ($r) => isset($r['customer']) ? (object) $r['customer'] : null,
                'discountCodes' => fn ($r) => !empty($r['discount_codes']) ? array_map(fn ($d) => $d['code'], $r['discount_codes']) : [],
                'lineItems' => fn ($r) => $r['line_items'] ?? '',
            ],
        ]);
    }

    public static function products(array $products): ArrayCollection
    {
        return UniversalEntityConverter::convert($products, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'sku' => fn ($r) => $r['sku'] ?? '',
                'vendor' => fn ($r) => $r['vendor'] ?? '',
                'variants' => fn ($r) => self::productVariants($r['variants'] ?? []),
            ],
        ]);
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return UniversalEntityConverter::convert($productVariants, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'sku' => fn ($r) => $r['sku'] ?? '',
            ],
        ]);
    }

    public static function productCategories(array $productCategories, bool $isSmartCollection = false): ArrayCollection
    {
        return UniversalEntityConverter::convert($productCategories, [
            'channel' => 'shopify',
            'platform_id_field' => 'id',
            'date_field' => 'published_at',
            'context' => [
                'isSmartCollection' => $isSmartCollection,
            ],
        ]);
    }

    public static function collects(array $collects): ArrayCollection
    {
        $collectionsProducts = [];
        foreach ($collects as $collect) {
            if (isset($collect['collection_id']) && isset($collect['product_id'])) {
                $collectionsProducts[$collect['collection_id']][] = $collect['product_id'];
            }
        }

        return new ArrayCollection($collectionsProducts);
    }
}

