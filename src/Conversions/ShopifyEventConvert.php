<?php
declare(strict_types=1);
namespace Anibalealvarezs\ShopifyHubDriver\Conversions;

use Doctrine\Common\Collections\ArrayCollection;

final class ShopifyEventConvert
{
    public static function order(array $order, string $shopId): ArrayCollection
    {
        if (empty($order['id']) || $shopId === '' || empty($order['createdAt'])) throw new \UnexpectedValueException('Order event identity and timestamp are required.');
        $events = [(object)['channel' => 'shopify', 'name' => 'order_placed', 'platformId' => $order['id'], 'channeledAccountId' => $shopId,
            'data' => ['source_id' => $order['id'], 'order_id' => $order['id'], 'store_id' => $shopId, 'occurred_at' => $order['createdAt'], 'customer_id' => $order['customer']['id'] ?? null]]];
        foreach ($order['refunds'] ?? [] as $refund) {
            if (empty($refund['id']) || empty($refund['createdAt'])) throw new \UnexpectedValueException('Refund event identity and timestamp are required.');
            $events[] = (object)['channel' => 'shopify', 'name' => 'order_refunded', 'platformId' => $refund['id'], 'channeledAccountId' => $shopId,
                'data' => $refund + ['source_id' => $refund['id'], 'order_id' => $order['id'], 'store_id' => $shopId, 'occurred_at' => $refund['createdAt'], 'financial_status_verified' => false]];
        }
        return new ArrayCollection($events);
    }
}
