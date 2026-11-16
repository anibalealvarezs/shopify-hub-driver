<?php
declare(strict_types=1);
namespace Tests\Unit;

use Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider;
use Anibalealvarezs\ShopifyHubDriver\Drivers\ShopifyDriver;
use PHPUnit\Framework\TestCase;

final class ShopifyMultiAccountTest extends TestCase
{
    public function testAccountsRemainIsolatedAndRequireExplicitSelection(): void
    {
        $auth = new ShopifyAuthProvider([]);
        $auth->storeAccountCredentials('one', ['shop_name' => 'one.myshopify.com', 'access_token' => 'first']);
        $auth->storeAccountCredentials('two', ['shop_name' => 'two.myshopify.com', 'access_token' => 'second']);
        self::assertTrue($auth->hasCredentials());
        self::assertTrue($auth->isValid());
        self::assertSame('first', $auth->getCredentialsForAccount('one')['access_token']);
        self::assertArrayNotHasKey('access_token', $auth->getAccounts()['one']);
        $driver = new ShopifyDriver($auth);
        self::assertInstanceOf(\Anibalealvarezs\ShopifyApi\ShopifyApi::class, $driver->getApi(['account_id' => 'two']));
        $auth->removeAccountCredentials('one');
        self::assertNull($auth->getCredentialsForAccount('one'));
        $this->expectException(\RuntimeException::class);
        $driver->getApi();
    }

    public function testUnknownAccountNeverUsesLegacyToken(): void
    {
        $auth = new ShopifyAuthProvider(['shopify_auth' => ['access_token' => 'legacy', 'shop_name' => 'legacy']]);
        $this->expectException(\RuntimeException::class);
        (new ShopifyDriver($auth))->getApi(['account_id' => 'unknown']);
    }
    public function testWrongShopResponseIsRejectedBeforePersistence(): void
    {
        $auth = new ShopifyAuthProvider([]);
        $auth->storeAccountCredentials('one', ['shop_name' => 'one.myshopify.com', 'access_token' => 'first']);
        $api = $this->createMock(\Anibalealvarezs\ShopifyApi\ShopifyApi::class);
        $api->method('graphql')->willReturn(['data' => ['shop' => ['id' => 'other', 'myshopifyDomain' => 'other.myshopify.com']]]);
        $api->expects(self::never())->method('graphqlPaginate');
        $driver = $this->getMockBuilder(ShopifyDriver::class)->setConstructorArgs([$auth])->onlyMethods(['getApi'])->getMock();
        $driver->method('getApi')->willReturn($api);
        $driver->setDataProcessor(static function (): void { self::fail('Wrong shop must not be persisted.'); });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different shop');
        $driver->syncShopAndCustomers(['account_id' => 'one']);
    }

    public function testEncryptedCredentialsRoundTripAndRejectWrongKey(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) self::markTestSkipped('Sodium unavailable.');
        $path = tempnam(__DIR__, 'shopify-auth-');
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        try {
            $auth = new ShopifyAuthProvider($path, $key);
            $auth->storeAccountCredentials('one', ['shop_name' => 'one.myshopify.com', 'access_token' => 'private-test-token']);
            self::assertStringNotContainsString('private-test-token', file_get_contents($path));
            self::assertSame('private-test-token', (new ShopifyAuthProvider($path, $key))->getCredentialsForAccount('one')['access_token']);
            $auth->storeAccountCredentials('two', ['shop_name' => 'two.myshopify.com', 'access_token' => 'second-test-token']);
            $reloaded = new ShopifyAuthProvider($path, $key);
            self::assertSame('private-test-token', $reloaded->getCredentialsForAccount('one')['access_token']);
            self::assertSame('second-test-token', $reloaded->getCredentialsForAccount('two')['access_token']);
            $this->expectException(\RuntimeException::class);
            new ShopifyAuthProvider($path, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        } finally {
            if (is_file($path.'.lock')) unlink($path.'.lock');
            unlink($path);
        }
    }

    public function testFilePersistenceRequiresEncryptionKey(): void
    {
        $path = tempnam(__DIR__, 'shopify-auth-');
        try {
            $auth = new ShopifyAuthProvider($path);
            $this->expectException(\RuntimeException::class);
            $auth->setAccessToken('private-test-token', 'store');
        } finally {
            self::assertStringNotContainsString('private-test-token', file_get_contents($path));
            if (is_file($path.'.lock')) unlink($path.'.lock');
            unlink($path);
        }
    }
    public function testHostConfigurationLoadsEncryptedTokenFile(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) self::markTestSkipped('Sodium unavailable.');
        $path = tempnam(__DIR__, 'shopify-host-');
        $previous = getenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY');
        try {
            putenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY='.base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
            $auth = new ShopifyAuthProvider(['token_path' => $path]);
            $auth->storeAccountCredentials('one', ['shop_name' => 'one.myshopify.com', 'access_token' => 'host-test-token']);
            self::assertSame('host-test-token', (new ShopifyAuthProvider(['token_path' => $path]))->getCredentialsForAccount('one')['access_token']);
            self::assertStringNotContainsString('host-test-token', file_get_contents($path));
        } finally {
            putenv($previous === false ? 'SHOPIFY_CREDENTIAL_ENCRYPTION_KEY' : 'SHOPIFY_CREDENTIAL_ENCRYPTION_KEY='.$previous);
            if (is_file($path.'.lock')) unlink($path.'.lock');
            unlink($path);
        }
    }
    public function testMalformedCredentialFileIsNotSilentlyReset(): void
    {
        $path = tempnam(__DIR__, 'shopify-invalid-');
        file_put_contents($path, '{broken');
        try {
            $this->expectException(\RuntimeException::class);
            new ShopifyAuthProvider($path);
        } finally {
            self::assertSame('{broken', file_get_contents($path));
            if (is_file($path.'.lock')) unlink($path.'.lock');
            unlink($path);
        }
    }
    public function testStaleWriterCannotEraseAnotherStoresCredentials(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) self::markTestSkipped('Sodium unavailable.');
        $path = tempnam(__DIR__, 'shopify-concurrent-');
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        try {
            $first = new ShopifyAuthProvider($path, $key);
            $second = new ShopifyAuthProvider($path, $key);
            $first->storeAccountCredentials('one', ['shop_name' => 'one.myshopify.com', 'access_token' => 'first']);
            try {
                $second->storeAccountCredentials('two', ['shop_name' => 'two.myshopify.com', 'access_token' => 'second']);
                self::fail('Expected stale-write rejection');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('concurrently', $e->getMessage());
            }
            self::assertSame('first', (new ShopifyAuthProvider($path, $key))->getCredentialsForAccount('one')['access_token']);
        } finally {
            if (is_file($path.'.lock')) unlink($path.'.lock');
            unlink($path);
        }
    }
}
