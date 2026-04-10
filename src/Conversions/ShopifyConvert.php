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
