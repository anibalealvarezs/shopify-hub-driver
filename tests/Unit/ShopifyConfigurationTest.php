<?php

declare(strict_types=1);

namespace Tests\Unit;

use Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver;
use PHPUnit\Framework\TestCase;

final class ShopifyConfigurationTest extends TestCase
{
    public function testNestedUpdatePreservesCredentialsAndOtherChannels(): void
    {
        $driver = new ShopifyDriver();
        $config = ['channels' => ['shopify' => ['shopify_auth' => ['access_token' => 'secret', 'shop_name' => 'example'], 'stores' => ['old']], 'other' => ['enabled' => true]]];
        $result = $driver->updateConfiguration(['assets' => ['stores' => ['new']], 'enabled' => true], $config);
        self::assertSame(['new'], $result['channels']['shopify']['stores']);
        self::assertSame('secret', $result['channels']['shopify']['shopify_auth']['access_token']);
        self::assertTrue($result['channels']['other']['enabled']);
        self::assertArrayNotHasKey('assets', $result['channels']['shopify']);
    }

    public function testFlatUpdateDoesNotClearAssetsWhenOmitted(): void
    {
        $driver = new ShopifyDriver();
        $result = $driver->updateConfiguration(['enabled' => true], ['stores' => ['store'], 'shopify_auth' => ['access_token' => 'secret']]);
        self::assertSame(['store'], $result['stores']);
        self::assertSame('secret', $result['shopify_auth']['access_token']);
    }

    public function testExplicitEmptyAssetSelectionClearsSelection(): void
    {
        $result = (new ShopifyDriver())->updateConfiguration(['assets' => ['stores' => []]], ['stores' => ['store']]);
        self::assertSame([], $result['stores']);
    }
    public function testWorkerEntityModeSurvivesConfigurationHydration(): void
    {
        $config = (new ShopifyDriver())->validateConfig(['entity' => 'shop_orders']);
        self::assertSame('shop_orders', $config['entity']);
    }

    public function testDefaultWorkerLimit(): void
    {
        self::assertSame(2, ShopifyDriver::getDefaultMaxWorkers());
    }
    public function testAccountUpdatePreservesOtherStoresAndTokens(): void
    {
        $result = (new ShopifyDriver())->updateConfiguration(['accounts' => ['one' => ['shop_name' => ' ONE.myshopify.com ']]], ['accounts' => [
            'one' => ['shop_name' => 'one.myshopify.com', 'access_token' => 'first'],
            'two' => ['shop_name' => 'two.myshopify.com', 'access_token' => 'second'],
        ]]);
        self::assertSame('one.myshopify.com', $result['accounts']['one']['shop_name']);
        self::assertSame('first', $result['accounts']['one']['access_token']);
        self::assertSame('second', $result['accounts']['two']['access_token']);
        self::assertSame('two', (new ShopifyDriver())->validateConfig(['account_id' => 'two'])['account_id']);
    }

    public function testAccountUpdateRejectsUntrustedDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ShopifyDriver())->updateConfiguration(['accounts' => ['one' => ['shop_name' => 'https://unrelated.test/path']]], []);
    }

    public function testInstanceRulesAndAssetPatternsExposeStoreSyncMetadata(): void
    {
        $rules = ShopifyDriver::getInstanceRules();
        self::assertSame(6, $rules['history_months']);
        self::assertSame('entities', $rules['entities_sync']);
        self::assertSame(3, $rules['recent_cron_hour']);
        self::assertSame(0, $rules['recent_cron_minute']);

        $patterns = ShopifyDriver::getAssetPatterns();
        self::assertSame('stores', $patterns['shopify_store']['key']);
        self::assertSame('sh:store', $patterns['shopify_store']['prefix']);
        self::assertSame('shopify', ShopifyDriver::getCommonConfigKey());
    }

    public function testChanneledAccountMappingBuildsStoreDescriptor(): void
    {
        $accounts = ShopifyDriver::getChanneledAccounts([
            'platform_id' => 'gid://shopify/Shop/101',
            'name' => 'Demo Store',
            'shop_domain' => 'demo.myshopify.com',
            'account_id' => 'store_101',
            'enabled' => true,
        ]);

        self::assertCount(1, $accounts);
        self::assertSame('gid://shopify/Shop/101', $accounts[0]['platformId']);
        self::assertSame('Demo Store', $accounts[0]['name']);
        self::assertSame('store', $accounts[0]['type']);
        self::assertTrue($accounts[0]['enabled']);
        self::assertSame('demo.myshopify.com', $accounts[0]['data']['myshopify_domain']);
        self::assertSame('store_101', $accounts[0]['data']['account_id']);
    }

    public function testStoreCredentialsPersistsMultiAccountEncryptedStore(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            self::markTestSkipped('Sodium unavailable.');
        }
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shopify_cred_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);
        $tokenPath = $tmpDir . DIRECTORY_SEPARATOR . 'shopify_tokens.json';
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $previousKey = getenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY');
        putenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY=' . $key);

        try {
            ShopifyDriver::storeCredentials([
                'token_path' => $tokenPath,
                'accounts' => [
                    'store_a' => [
                        'shop_name' => 'Store-A.myshopify.com',
                        'access_token' => 'shpat_test_123',
                        'name' => 'Store A',
                    ],
                ],
            ]);

            $auth = new \Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider(['token_path' => $tokenPath]);
            $creds = $auth->getCredentialsForAccount('store_a');
            self::assertNotNull($creds);
            self::assertSame('store-a.myshopify.com', $creds['shop_name']);
            self::assertSame('shpat_test_123', $creds['access_token']);
        } finally {
            if ($previousKey === false) {
                putenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY');
            } else {
                putenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY=' . $previousKey);
            }
            @unlink($tokenPath);
            @unlink($tokenPath . '.lock');
            @rmdir($tmpDir);
        }
    }
}
