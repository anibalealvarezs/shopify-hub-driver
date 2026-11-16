<?php

declare(strict_types=1);

namespace Tests\Unit;

use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver;
use PHPUnit\Framework\TestCase;

final class ShopifyConnectionTest extends TestCase
{
    public function testConnectionUsesReportedStoreIdentity(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'name' => 'Store', 'myshopifyDomain' => 'store.myshopify.com']]]);
        $auth = $this->createMock(AuthProviderInterface::class);
        $auth->method('hasCredentials')->willReturn(true);
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->setAuthProvider($auth);
        $driver->method('getApi')->willReturn($api);
        $result = $driver->validateAuthentication();
        self::assertTrue($result['success']);
        self::assertSame('gid://shopify/Shop/1', $result['details']['stores'][0]['platform_id']);
        self::assertSame('store.myshopify.com', $result['details']['stores'][0]['shop_domain']);
    }

    public function testFailedRequestDoesNotReportSuccessOrExposeProviderError(): void
    {
        $api = $this->createMock(ShopifyApi::class);
        $api->method('graphql')->willThrowException(new \RuntimeException('private-provider-details'));
        $auth = $this->createMock(AuthProviderInterface::class);
        $auth->method('hasCredentials')->willReturn(true);
        $driver = $this->getMockBuilder(ShopifyDriver::class)->onlyMethods(['getApi'])->getMock();
        $driver->setAuthProvider($auth);
        $driver->method('getApi')->willReturn($api);
        $result = $driver->validateAuthentication();
        self::assertFalse($result['success']);
        self::assertStringNotContainsString('private-provider-details', json_encode($result));
        self::assertSame([], $driver->fetchAvailableAssets());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to discover');
        $driver->fetchAvailableAssets(true);
    }
}
