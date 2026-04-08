<?php

namespace Anibalealvarezs\ShopifyHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ShopifyApi\ShopifyApi;
use Symfony\Component\HttpFoundation\Response;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;

class ShopifyDriver implements SyncDriverInterface
{
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
                    callback: function ($orders) use ($config) {
                        ($this->dataProcessor)(
                            data: $orders,
                            type: 'orders',
                            config: $config
                        );
                    }
                );
            }

            // 2. Sync Products
            if ($type === 'all' || $type === 'products') {
                if ($this->logger) $this->logger->info("Syncing Shopify Products...");
                $api->getAllProductsAndProcess(
                    callback: function ($products) use ($config) {
                        ($this->dataProcessor)(
                            data: $products,
                            type: 'products',
                            config: $config
                        );
                    }
                );
            }

            // 3. Sync Customers
            if ($type === 'all' || $type === 'customers') {
                if ($this->logger) $this->logger->info("Syncing Shopify Customers...");
                $api->getAllCustomersAndProcess(
                    createdAtMin: $startDate->format('Y-m-d\TH:i:sP'),
                    createdAtMax: $endDate->format('Y-m-d\TH:i:sP'),
                    callback: function ($customers) use ($config) {
                        ($this->dataProcessor)(
                            data: $customers,
                            type: 'customers',
                            config: $config
                        );
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
}
