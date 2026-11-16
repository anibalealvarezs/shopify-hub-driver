<?php

declare(strict_types=1);

namespace Anibalealvarezs\ShopifyHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
use Doctrine\Common\Collections\ArrayCollection;

/** Convert only daily ShopifyQL sales metrics at shop/account grain. */
final class ShopifyMetricConvert
{
    private const METRICS = ['orders' => 'orders', 'gross_sales' => 'gross_sales', 'net_sales' => 'net_sales', 'total_sales' => 'total_sales'];

    public static function dailySales(array $table, string $shopId, string $currency, \DateTimeInterface $start, \DateTimeInterface $end): ArrayCollection
    {
        if ($shopId === '' || !preg_match('/^[A-Z]{3}$/D', $currency)) throw new \InvalidArgumentException('Shop identity and ISO currency are required.');
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        if ($startDate > $endDate) throw new \InvalidArgumentException('Invalid ShopifyQL date interval.');
        if (!isset($table['rows']) || !is_array($table['rows'])) throw new \UnexpectedValueException('ShopifyQL rows are missing.');
        $rows = [];
        $seen = [];
        foreach ($table['rows'] as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('Invalid ShopifyQL row.');
            $day = $row['day'] ?? null;
            $date = is_string($day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
            if (!$date || $date->format('Y-m-d') !== $day || $day < $startDate || $day > $endDate || isset($seen[$day])) {
                throw new \UnexpectedValueException('Invalid or duplicate ShopifyQL day.');
            }
            $seen[$day] = true;
            foreach (self::METRICS as $key => $_) {
                if (!array_key_exists($key, $row) || ($row[$key] !== null && (!is_numeric($row[$key]) || !is_finite((float)$row[$key])))) {
                    throw new \UnexpectedValueException('Invalid ShopifyQL sales value.');
                }
            }
            $row['shop_id'] = $shopId;
            $rows[] = $row;
        }
        $metrics = UniversalMetricConverter::convert($rows, [
            'channel' => 'shopify', 'period' => 'daily', 'date_field' => 'day',
            'platform_id_field' => 'shop_id', 'metrics' => self::METRICS,
            'row_key_fields' => ['shop_id' => ['channeledAccount']],
            'context' => UniversalMetricConverter::getUniversalContext(['currency' => $currency, 'sourceCurrency' => $currency]),
        ]);
        foreach ($metrics as $metric) {
            $metric->isMonetary = $metric->name !== 'orders';
            $metric->replaceExistingValue = true;
            $metric->requiresChanneledAccount = true;
        }
        return $metrics;
    }
}
