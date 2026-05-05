<?php

declare(strict_types=1);

namespace Tests\Unit;

use Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver;
use PHPUnit\Framework\TestCase;

final class ShopifyDriverCanonicalMetricDictionaryTest extends TestCase
{
    public function testExposesCanonicalMetricDictionary(): void
    {
        $dictionary = ShopifyDriver::getCanonicalMetricDictionary();

        $this->assertArrayHasKey('conversions', $dictionary);
        $this->assertArrayHasKey('conversion_rate', $dictionary);
        $this->assertArrayHasKey('roas_purchase', $dictionary);
        $this->assertContains('orders', $dictionary['conversions']);
    }
}

