<?php

namespace Anibalealvarezs\ShopifyHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Traits\HasUpdatableCredentials;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyConvert;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;

class ShopifyDriver implements SyncDriverInterface
{
    use SyncDriverTrait;

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

    /**
     * Get the public resources exposed by this driver.
     * 
     * @return array
     */
    public static function getPublicResources(): array
    {
        return [];
    }

    /**
     * Get the display label for the channel.
     * 
     * @return string
     */
    public static function getChannelLabel(): string
    {
        return 'Shopify';
    }

    /**
     * Get the routes served by this driver.
     * 
     * @return array
     */
    public static function getRoutes(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function fetchAvailableAssets(bool $throwOnError = false): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function validateAuthentication(): array
    {
        return [
            'success' => true,
            'message' => 'Status unknown for this driver.',
            'details' => []
        ];
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

            // 4. Sync Price Rules
            if ($type === 'all' || $type === 'price_rules') {
                $this->syncPriceRules($api, $startDate, $endDate, $config);
            }

            // 5. Sync Product Categories
            if ($type === 'all' || $type === 'product_categories') {
                $this->syncProductCategories($api, $startDate, $endDate, $config);
            }

            return new Response(json_encode(['status' => 'success', 'message' => "Shopify sync [{$type}] completed"]));

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("ShopifyDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function syncPriceRules(ShopifyApi $api, DateTime $startDate, DateTime $endDate, array $config): void
    {
        if ($this->logger) {
            $this->logger->info("Syncing Shopify Price Rules...");
        }

        $sinceId = $config['sinceId'] ?? ($config['filters']->sinceId ?? null);
        $resume = $config['resume'] ?? true;

        $api->getAllPriceRulesAndProcess(
            createdAtMin: $startDate->format('Y-m-d\TH:i:sP'),
            createdAtMax: $endDate->format('Y-m-d\TH:i:sP'),
            endsAtMin: $config['filters']->endsAtMin ?? null,
            endsAtMax: $config['filters']->endsAtMax ?? null,
            sinceId: $sinceId,
            startsAtMin: $config['filters']->startsAtMin ?? null,
            startsAtMax: $config['filters']->startsAtMax ?? null,
            timesUsed: $config['filters']->timesUsed ?? null,
            updatedAtMin: $config['filters']->updatedAtMin ?? null,
            updatedAtMax: $config['filters']->updatedAtMax ?? null,
            pageInfo: $config['filters']->pageInfo ?? null,
            callback: function ($priceRules) use ($api, $config) {
                foreach ($priceRules as &$priceRule) {
                    $discountCodesList = [];
                    $api->getAllDiscountCodesAndProcess(
                        priceRuleId: $priceRule['id'],
                        callback: function ($discountCodes) use (&$discountCodesList) {
                            $discountCodesList = array_merge($discountCodesList, $discountCodes);
                        }
                    );
                    $priceRule['_discounts'] = ShopifyConvert::discounts($discountCodesList);
                }
                $collection = ShopifyConvert::priceRules($priceRules);
                if ($this->dataProcessor && $collection->count() > 0) {
                    ($this->dataProcessor)($collection, $this->logger);
                }
            }
        );
    }

    private function syncProductCategories(ShopifyApi $api, DateTime $startDate, DateTime $endDate, array $config): void
    {
        if ($this->logger) {
            $this->logger->info("Syncing Shopify Product Categories...");
        }

        $sourceCustomCollections = $api->getAllCustomCollections(
            fields: $config['fields'] ?? null,
            ids: $config['filters']->ids ?? null,
            publishedAtMin: $startDate->format('Y-m-d\TH:i:sP'),
            publishedAtMax: $endDate->format('Y-m-d\TH:i:sP'),
            sinceId: $config['sinceId'] ?? null,
            updatedAtMin: $config['filters']->updatedAtMin ?? null,
            updatedAtMax: $config['filters']->updatedAtMax ?? null,
            pageInfo: $config['filters']->pageInfo ?? null,
        );
        $sourceSmartCollections = $api->getAllSmartCollections(
            fields: $config['fields'] ?? null,
            ids: $config['filters']->ids ?? null,
            publishedAtMin: $startDate->format('Y-m-d\TH:i:sP'),
            publishedAtMax: $endDate->format('Y-m-d\TH:i:sP'),
            sinceId: $config['sinceId'] ?? null,
            updatedAtMin: $config['filters']->updatedAtMin ?? null,
            updatedAtMax: $config['filters']->updatedAtMax ?? null,
            pageInfo: $config['filters']->pageInfo ?? null,
        );
        $sourceCollects = $api->getAllCollects(
            pageInfo: $config['filters']->pageInfo ?? null,
        );

        $collection = new \Doctrine\Common\Collections\ArrayCollection(
            [
                ...ShopifyConvert::productCategories(productCategories: $sourceCustomCollections['custom_collections'])->toArray(),
                ...ShopifyConvert::productCategories(productCategories: $sourceSmartCollections['smart_collections'], isSmartCollection: true)->toArray(),
            ]
        );
        
        $collects = ShopifyConvert::collects($sourceCollects['collects'])->toArray();

        if ($this->dataProcessor && ($collection->count() > 0 || !empty($collects))) {
            ($this->dataProcessor)($collection, $this->logger, $collects);
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
                'enabled' => false,
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
        return \Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService::hydrate(
            $this->getChannel(),
            'global',
            $config
        );
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

    /**
     * @inheritdoc
     */
    public function initializeEntities(mixed $entityManager, array $config = []): array
    {
        return ['initialized' => 0, 'skipped' => 0];
    }

    /**
     * @inheritdoc
     */
    public function reset(mixed $entityManager, string $mode = 'all', array $config = []): array
    {
        if (!$entityManager instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \Exception("EntityManagerInterface required for ShopifyDriver reset.");
        }

        $resetter = new \Anibalealvarezs\ShopifyHubDriver\Services\ShopifyResetService($entityManager);
        return $resetter->reset($this->getChannel(), $mode);
    }

    public function updateConfiguration(array $newData, array $currentConfig): array
    {
        return $currentConfig;
    }

    public function prepareUiConfig(array $channelConfig): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getDateFilterMapping(): array
    {
        return [
            'start' => 'createdAtMin',
            'end' => 'createdAtMax'
        ];
    }
}

