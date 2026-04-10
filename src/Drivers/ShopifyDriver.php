<?php

namespace Anibalealvarezs\ShopifyHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiSkeleton\Traits\HasUpdatableCredentials;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyConvert;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;

class ShopifyDriver implements SyncDriverInterface
{

    /**
     * Store credentials for this driver.
     * 
     * @param array $credentials
     * @return void
     */
    public static function storeCredentials(array $credentials): void
    {
        // No implementation needed for this driver
    }

    public static function getCommonConfigKey(): ?string
    {
        return null;
    }
    use HasUpdatableCredentials;

    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;
    /** @var callable|null */
    private $dataProcessor = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getAuthProvider(): ?AuthProviderInterface
    {
        return $this->authProvider;
    }

    public function setDataProcessor(callable $processor): void
    {
        $this->dataProcessor = $processor;
    }

    public function getChannel(): string
    {
        return 'shopify';
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for ShopifyDriver");
        }

        if (!$this->dataProcessor) {
            throw new Exception("DataProcessor not set for ShopifyDriver");
        }

        if ($this->logger) {
            $this->logger->info("Starting ShopifyDriver sync (Modular)...");
        }

        try {
            /** @var \Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider $auth */
            $auth = $this->authProvider;
            
            $api = new ShopifyApi(
                apiKey: $auth->getAccessToken(),
                shopName: $auth->getShopName(),
                version: $auth->getVersion()
            );

            $type = $config['type'] ?? 'all';

            // 1. Sync Orders
            if ($type === 'all' || $type === 'orders') {
                if ($this->logger) $this->logger->info("Syncing Shopify Orders...");
                $api->getAllOrdersAndProcess(
                    createdAtMin: $startDate->format('Y-m-d\TH:i:sP'),
                    createdAtMax: $endDate->format('Y-m-d\TH:i:sP'),
                    processedAtMin: $config['processedAtMin'] ?? null,
                    processedAtMax: $config['processedAtMax'] ?? null,
                    fields: $config['fields'] ?? null,
                    callback: function ($orders) {
                        $collection = ShopifyConvert::orders($orders);
                        if ($this->dataProcessor && $collection->count() > 0) {
                            ($this->dataProcessor)($collection, $this->logger);
                        }
                    }
                );
            }

            // 2. Sync Products
            if ($type === 'all' || $type === 'products') {
                if ($this->logger) $this->logger->info("Syncing Shopify Products...");
                $api->getAllProductsAndProcess(
                    callback: function ($products) {
                        $collection = ShopifyConvert::products($products);
                        if ($this->dataProcessor && $collection->count() > 0) {
                            ($this->dataProcessor)($collection, $this->logger);
                        }
                    }
                );
            }

            // 3. Sync Customers
            if ($type === 'all' || $type === 'customers') {
                if ($this->logger) $this->logger->info("Syncing Shopify Customers...");
                $api->getAllCustomersAndProcess(
                    createdAtMin: $startDate->format('Y-m-d\TH:i:sP'),
                    createdAtMax: $endDate->format('Y-m-d\TH:i:sP'),
                    callback: function ($customers) {
                        $collection = ShopifyConvert::customers($customers);
                        if ($this->dataProcessor && $collection->count() > 0) {
                            ($this->dataProcessor)($collection, $this->logger);
                        }
                    }
                );
            }

            return new Response(json_encode(['status' => 'success', 'message' => "Shopify sync [{$type}] completed"]));

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("ShopifyDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    public function getApi(array $config = []): ShopifyApi
    {
        /** @var \Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider $auth */
        $auth = $this->authProvider;
        
        return new ShopifyApi(
            apiKey: $auth->getAccessToken(),
            shopName: $auth->getShopName(),
            version: $auth->getVersion()
        );
    }

    /**
     * @inheritdoc
     */
    public function getConfigSchema(): array
    {
        return [
            'global' => [
                'enabled' => true,
                'cache_history_range' => '30 days',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'enabled' => true,
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function validateConfig(array $config): array
    {
        return $config;
    }

    /**
     * @inheritdoc
     */
    public function seedDemoData(SeederInterface $seeder, array $config = []): void
    {
        // Placeholder for future implementation
    }
    public function boot(): void
    {
    }

    /**
     * @inheritdoc
     */
    public function getAssetPatterns(): array
    {
        return [
            'shopify_store' => [
                'prefix' => 'sh:store',
                'hostnames' => ['myshopify.com'],
                'url_id_regex' => null
            ]
        ];
    }
}

