<?php

    namespace Anibalealvarezs\ShopifyHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Classes\MetricProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Classes\AggregationProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Interfaces\MetricProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AggregationProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Traits\HasUpdatableCredentials;
    use Anibalealvarezs\ShopifyApi\ShopifyApi;
    use Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider;
    use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyConvert;
    use Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyMetricConvert;
    use Symfony\Component\HttpFoundation\Response;
    use Psr\Log\LoggerInterface;
    use DateTime;
    use Exception;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;

    class ShopifyDriver implements SyncDriverInterface, MetricProfileProviderInterface, AggregationProfileProviderInterface, CanonicalMetricDictionaryProviderInterface, \Anibalealvarezs\ApiDriverCore\Interfaces\PreAggregationProviderInterface, ChanneledAccountableInterface
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
            $auth = new ShopifyAuthProvider($credentials['token_path'] ?? '');
            $accounts = $credentials['accounts'] ?? null;
            if (!empty($accounts) && is_array($accounts)) {
                foreach ($accounts as $accountId => $account) {
                    if (is_array($account)) {
                        $auth->storeAccountCredentials((string) $accountId, $account);
                    }
                }
                return;
            }
            if (!empty($credentials['account_id']) || (!empty($credentials['shop_name']) && !empty($credentials['access_token']) && str_contains((string) $credentials['shop_name'], '.myshopify.com'))) {
                $accountId = (string) ($credentials['account_id'] ?? $credentials['shop_id'] ?? $credentials['shop_name']);
                $auth->storeAccountCredentials($accountId, $credentials);
                return;
            }
            if (!empty($credentials['access_token'])) {
                $auth->setAccessToken(
                    (string) $credentials['access_token'],
                    (string) ($credentials['shop_name'] ?? $credentials['user_id'] ?? ''),
                    (string) ($credentials['version'] ?? '')
                );
            }
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

        public static function getDefaultMaxWorkers(): int
        {
            return 2;
        }

        public static function getPreAggregationRules(): array
        {
            // ShopifyQL supplies financial daily totals. Atomic order/refund records
            // must not be reduced into a competing definition of those metrics.
            return [];
        }

        public static function getDefaultAttributionWindowDays(): int
        {
            return 30;
        }

        public function syncEntities(array $config = [], ?callable $shouldContinue = null): Response
        {
            return $this->syncShopCommerce($config, $shouldContinue);
        }

        public function syncMetrics(DateTime $start, DateTime $end, array $config = [], ?callable $shouldContinue = null): Response
        {
            $cursor = clone $start;
            while ($cursor <= $end) {
                $quarterEndMonth = (int)(ceil((int)$cursor->format('n') / 3) * 3);
                $chunkEnd = (clone $cursor)->setDate((int)$cursor->format('Y'), $quarterEndMonth, 1)->modify('last day of this month');
                if ($chunkEnd > $end) $chunkEnd = clone $end;
                $this->syncDailySales($cursor, $chunkEnd, $config, $shouldContinue);
                $cursor = (clone $chunkEnd)->modify('+1 day');
            }
            if ($start > $end) throw new \InvalidArgumentException('Invalid Shopify metric date interval.');
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify metrics sync completed'], JSON_THROW_ON_ERROR));
        }

        public static function getMetricProfiles(): array
        {
            return [
                MetricProfileTemplates::pageTotals(
                    channel: 'shopify',
                    key: 'shopify_store_totals',
                    label: 'Shopify Store Totals'
                ),
            ];
        }

        public static function getAggregationProfiles(): array
        {
            return [
                AggregationProfileTemplates::storeProfile(
                    channel: 'shopify',
                    key: 'shopify_store_performance',
                    label: 'Shopify Store Performance'
                ),
            ];
        }

        public static function getCanonicalMetricDictionary(): array
        {
            return [
                'conversions'     => ['orders', 'orders_daily'],
                'conversion_rate' => ['conversion_rate', 'store_conversion_rate'],
                'roas_purchase'   => ['roas', 'purchase_roas'],
            ];
        }

        public static function getPlatformEntityIdField(): string
        {
            return 'shop_domain';
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
            if ($this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface && ($accounts = $this->authProvider->getAccounts()) !== []) {
                $assets = [];
                foreach ($accounts as $id => $account) {
                    try {
                        $shop = $this->getApi(['account_id' => (string)$id])->graphql('query ShopifyConnection { shop { id name myshopifyDomain } }')['data']['shop'] ?? null;
                        if (!is_array($shop) || empty($shop['id']) || empty($shop['myshopifyDomain'])) throw new \UnexpectedValueException('Shopify shop identity is missing.');
                        $assets[] = ['account_id' => (string)$id, 'platform_id' => $shop['id'], 'name' => $shop['name'] ?? $shop['myshopifyDomain'], 'type' => 'store', 'shop_domain' => $shop['myshopifyDomain']];
                    } catch (\Throwable $e) {
                        if ($throwOnError) throw new \RuntimeException('Unable to discover a configured Shopify store.');
                    }
                }
                return $assets;
            }
            try {
                $shop = $this->getApi()->graphql('query ShopifyConnection { shop { id name myshopifyDomain } }')['data']['shop'] ?? null;
                if (!is_array($shop) || !is_string($shop['id'] ?? null) || $shop['id'] === '' || !is_string($shop['myshopifyDomain'] ?? null) || $shop['myshopifyDomain'] === '') {
                    throw new \UnexpectedValueException('Shopify shop identity is missing.');
                }
                return [['account_id' => $shop['id'], 'platform_id' => $shop['id'], 'name' => $shop['name'] ?? $shop['myshopifyDomain'], 'type' => 'store', 'shop_domain' => $shop['myshopifyDomain']]];
            } catch (\Throwable $e) {
                if ($throwOnError) throw new \RuntimeException('Unable to discover the Shopify store.');
                return [];
            }
        }

        /**
         * @inheritdoc
         */
        public function validateAuthentication(): array
        {
            if (!$this->authProvider || (!$this->authProvider->hasCredentials() && !($this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface && $this->authProvider->getAccounts() !== []))) {
                return [
                    'success' => false,
                    'message' => 'Credentials not configured.',
                    'details' => []
                ];
            }

            try {
                $assets = $this->fetchAvailableAssets(true);
                return ['success' => true, 'message' => 'Shopify connection verified.', 'details' => ['stores' => $assets]];
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'Unable to verify the Shopify connection.', 'details' => []];
            }
        }

        public static function getCommonConfigKey(): ?string
        {
            return 'shopify';
        }

        public static function getChanneledAccounts(array $asset): array
        {
            $platformId = self::getChanneledAccountPlatformId($asset);
            if ($platformId === '') {
                return [];
            }

            $platformCreatedAt = self::getChanneledAccountPlatformCreatedAt($asset);

            return [
                [
                    'platformId'        => $platformId,
                    'platformCreatedAt' => $platformCreatedAt !== '' ? $platformCreatedAt : null,
                    'name'              => self::getChanneledAccountName($asset),
                    'type'              => self::getChanneledAccountType(),
                    'enabled'           => filter_var($asset['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'data'              => self::getChanneledAccountData($asset),
                ]
            ];
        }

        public static function getChanneledAccountPlatformId(array $asset, ?string $key = null): string
        {
            return (string) ($asset['platform_id'] ?? $asset['id'] ?? $asset['shop_domain'] ?? '');
        }

        public static function getChanneledAccountPlatformCreatedAt(array $asset, ?string $key = null): string
        {
            return (string) ($asset['created_at'] ?? $asset['platformCreatedAt'] ?? '');
        }

        public static function getChanneledAccountName(array $asset, ?string $key = null): string
        {
            return (string) ($asset['name'] ?? $asset['shop_domain'] ?? '');
        }

        public static function getChanneledAccountType(): string
        {
            return 'store';
        }

        public static function getChanneledAccountData(array $asset, ?string $key = null): array
        {
            $data = is_array($asset['data'] ?? null) ? $asset['data'] : [];
            if (!empty($asset['shop_domain'])) {
                $data['myshopify_domain'] ??= (string) $asset['shop_domain'];
            }
            if (!empty($asset['account_id'])) {
                $data['account_id'] ??= (string) $asset['account_id'];
            }
            return $data;
        }

        /**
         * Returns a mapping of environment variables to configuration keys for this driver.
         *
         * @return array
         */
        public static function getEnvMapping(): array
        {
            return [
                'shopify' => [
                    'SHOPIFY_TOKEN_PATH'   => 'token_path',
                    'SHOPIFY_ACCESS_TOKEN' => 'shopify_auth.access_token',
                    'SHOPIFY_SHOP_NAME'    => 'shopify_auth.shop_name',
                    'SHOPIFY_API_VERSION'  => 'shopify_auth.version',
                ],
            ];
        }

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

        /**
         * Resolve credentials for the requested Shopify account from the auth provider or inline channel config,
         * matching by account key, numeric shop_id, shop_gid, or myshopify_domain.
         */
        private function resolveAccountCredentials(array $config): ?array
        {
            $rawId = trim((string)($config['account_id'] ?? ''));
            if ($rawId === '') {
                return null;
            }

            $numericId = preg_match('#^(?:gid://shopify/Shop/|shopify_)?(\d+)$#i', $rawId, $m) ? $m[1] : null;
            $candidates = array_values(array_unique(array_filter([
                $rawId,
                $numericId !== null ? 'shopify_' . $numericId : null,
                $numericId,
                $numericId !== null ? 'gid://shopify/Shop/' . $numericId : null,
            ])));

            if ($this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface) {
                foreach ($candidates as $candidate) {
                    $creds = $this->authProvider->getCredentialsForAccount($candidate);
                    if (is_array($creds) && !empty($creds['access_token']) && !empty($creds['shop_name'])) {
                        return $creds;
                    }
                }
            }

            $configAccounts = $config['accounts'] ?? [];
            if (is_array($configAccounts)) {
                foreach ($candidates as $candidate) {
                    if (isset($configAccounts[$candidate]) && is_array($configAccounts[$candidate])) {
                        $creds = $configAccounts[$candidate];
                        if (!empty($creds['access_token']) && !empty($creds['shop_name'])) {
                            return $creds;
                        }
                    }
                }
                foreach ($configAccounts as $creds) {
                    if (!is_array($creds) || empty($creds['access_token']) || empty($creds['shop_name'])) {
                        continue;
                    }
                    $credsShopId = (string)($creds['shop_id'] ?? '');
                    $credsShopGid = (string)($creds['shop_gid'] ?? '');
                    $credsDomain = strtolower(trim((string)($creds['myshopify_domain'] ?? $creds['shop_name'] ?? '')));
                    if (
                        in_array($credsShopId, $candidates, true)
                        || in_array($credsShopGid, $candidates, true)
                        || ($credsDomain !== '' && $credsDomain === strtolower($rawId))
                    ) {
                        return $creds;
                    }
                }
            }

            return null;
        }

        private function verifySelectedShop(array $shop, array $config): void
        {
            if (empty($config['account_id'])) return;
            $credentials = $this->resolveAccountCredentials($config);
            if (!$credentials) {
                if (!($this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface)) {
                    return;
                }
                throw new \RuntimeException('Shopify returned a different shop than the selected account.');
            }
            $id = (string)$config['account_id'];
            $expectedId = $credentials['shop_gid'] ?? $credentials['shop_id'] ?? (str_starts_with($id, 'gid://shopify/Shop/') ? $id : null);
            $actualId = (string)($shop['id'] ?? '');
            $expectedNumeric = $expectedId !== null && preg_match('#^gid://shopify/Shop/(\d+)$#i', (string)$expectedId, $em) ? $em[1] : (string)$expectedId;
            $actualNumeric = preg_match('#^gid://shopify/Shop/(\d+)$#i', $actualId, $am) ? $am[1] : $actualId;
            $domain = strtolower((string)($shop['myshopifyDomain'] ?? ''));
            if ($domain === '' || $domain !== strtolower(trim((string)($credentials['shop_name'] ?? ''))) || ($expectedId !== null && $actualId !== (string)$expectedId && $actualNumeric !== $expectedNumeric)) {
                throw new \RuntimeException('Shopify returned a different shop than the selected account.');
            }
        }

        protected function streamBulkQuery(ShopifyApi $api, string $query, callable $callback, array $config, ?callable $shouldContinue): void
        {
            $service = new \Anibalealvarezs\ShopifyApi\Services\BulkOperationsService($api);
            $operation = $service->start($query);
            $completed = $service->wait((string)$operation['id'], (int)($config['bulk_poll_attempts'] ?? 120), (int)($config['bulk_poll_interval_ms'] ?? 1000), $shouldContinue);
            $service->download($completed, $callback, $shouldContinue);
        }

        private function rootConnection(ShopifyApi $api, string $query, string $connection, callable $callback, array $config, ?callable $shouldContinue): void
        {
            if (($config['transport'] ?? 'graphql') !== 'bulk') {
                $api->graphqlPaginate($query, $connection, $callback, [], $shouldContinue);
                return;
            }
            // Extract the existing root node selection, preserving nested scalar
            // objects. Bulk roots stream independently; child connections are
            // completed through the same guarded pagination as ordinary sync.
            $start = strpos($query, 'nodes {');
            if ($start === false) throw new \LogicException('Missing bulk node selection.');
            $start = strpos($query, '{', $start) + 1;
            $depth = 1;
            for ($end = $start; $end < strlen($query); $end++) {
                if ($query[$end] === '{') $depth++;
                if ($query[$end] === '}' && --$depth === 0) break;
            }
            if ($depth !== 0) throw new \LogicException('Invalid bulk node selection.');
            $bulkQuery = '{ '.$connection.' { edges { node { '.substr($query, $start, $end - $start).' } } } }';
            $this->streamBulkQuery($api, $bulkQuery, static function (array $row) use ($callback): void {
                if (isset($row['__parentId'])) throw new \UnexpectedValueException('Unexpected nested bulk record.');
                $callback(['nodes' => [$row]]);
            }, $config, $shouldContinue);
        }

        /** Synchronize shop ownership and customer records using the GraphQL Admin API. */
        public function syncShopAndCustomers(array $config = [], ?callable $shouldContinue = null): Response
        {
            if (!$this->dataProcessor) {
                throw new \RuntimeException('DataProcessor not set for ShopifyDriver');
            }
            if ($shouldContinue && !$shouldContinue()) {
                throw new \RuntimeException('Shopify sync cancelled.');
            }
            $api = $this->getApi($config);
            $response = $api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }');
            $shop = $response['data']['shop'] ?? null;
            if (!is_array($shop) || empty($shop['id'])) {
                throw new \UnexpectedValueException('Shopify shop identity is missing.');
            }
            if ($shouldContinue && !$shouldContinue()) {
                throw new \RuntimeException('Shopify sync cancelled.');
            }
            $this->verifySelectedShop($shop, $config);
            ($this->dataProcessor)(ShopifyConvert::shopAccount($shop), 'account');
            ($this->dataProcessor)(ShopifyConvert::shopStore($shop), 'store');
            $shopId = (string) $shop['id'];
            $this->rootConnection($api,
                'query ShopifyCustomers($cursor: String) { customers(first: 100, after: $cursor) { nodes { id legacyResourceId createdAt email firstName lastName } pageInfo { hasNextPage endCursor } } }',
                'customers',
                function (array $page) use ($shopId, $shouldContinue): void {
                    if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                    if (!isset($page['nodes']) || !is_array($page['nodes'])) throw new \UnexpectedValueException('Invalid Shopify customer page.');
                    $collection = ShopifyConvert::graphqlCustomers($page['nodes'], $shopId);
                    if ($collection->count() > 0) ($this->dataProcessor)($collection, 'customer');
                },
                $config,
                $shouldContinue,
            );
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify shop and customer sync completed'], JSON_THROW_ON_ERROR));
        }

        /** Synchronize products and all variants through independently paginated connections. */
        public function syncShopAndProducts(array $config = [], ?callable $shouldContinue = null): Response
        {
            if (!$this->dataProcessor) throw new \RuntimeException('DataProcessor not set for ShopifyDriver');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $api = $this->getApi($config);
            $response = $api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }');
            $shop = $response['data']['shop'] ?? null;
            if (!is_array($shop) || empty($shop['id'])) throw new \UnexpectedValueException('Shopify shop identity is missing.');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $this->verifySelectedShop($shop, $config);
            ($this->dataProcessor)(ShopifyConvert::shopAccount($shop), 'account');
            ($this->dataProcessor)(ShopifyConvert::shopStore($shop), 'store');
            $shopId = (string) $shop['id'];
            $this->rootConnection($api,
                'query ShopifyProducts($cursor: String) { products(first: 100, after: $cursor) { nodes { id legacyResourceId title handle vendor createdAt updatedAt } pageInfo { hasNextPage endCursor } } }',
                'products',
                function (array $page) use ($api, $shopId, $shouldContinue): void {
                    if (!isset($page['nodes']) || !is_array($page['nodes'])) throw new \UnexpectedValueException('Invalid Shopify product page.');
                    foreach ($page['nodes'] as $product) {
                        if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                        if (!is_array($product) || !is_string($product['id'] ?? null) || $product['id'] === '') throw new \UnexpectedValueException('Shopify product identity is missing.');
                        $variants = [];
                        $api->graphqlPaginate(
                            'query ShopifyProductVariants($id: ID!, $cursor: String) { product(id: $id) { variants(first: 100, after: $cursor) { nodes { id legacyResourceId sku createdAt updatedAt } pageInfo { hasNextPage endCursor } } } }',
                            'product.variants',
                            static function (array $variantPage) use (&$variants, $shouldContinue): void {
                                if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                                if (!isset($variantPage['nodes']) || !is_array($variantPage['nodes'])) throw new \UnexpectedValueException('Invalid Shopify variant page.');
                                array_push($variants, ...$variantPage['nodes']);
                            },
                            ['id' => $product['id']], $shouldContinue,
                        );
                        if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                        $product['variants'] = ['nodes' => $variants, 'pageInfo' => ['hasNextPage' => false]];
                        $collections = [];
                        $api->graphqlPaginate('query ShopifyProductCollections($id: ID!, $cursor: String) { product(id: $id) { collections(first: 100, after: $cursor) { nodes { id title handle updatedAt ruleSet { appliedDisjunctively } } pageInfo { hasNextPage endCursor } } } }', 'product.collections',
                            static function (array $page) use (&$collections, $shouldContinue): void {
                                if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                                if (!is_array($page['nodes'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify collection page.');
                                array_push($collections, ...$page['nodes']);
                            }, ['id' => $product['id']], $shouldContinue);
                        $product['collections'] = ['nodes' => $collections, 'pageInfo' => ['hasNextPage' => false]];
                        ($this->dataProcessor)(ShopifyConvert::graphqlProducts([$product], $shopId), 'product');
                    }
                }, $config, $shouldContinue,
            );
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify shop and product sync completed'], JSON_THROW_ON_ERROR));
        }

        /** Synchronize accessible orders and all their line items; API scopes bound history. */
        public function syncShopAndOrders(array $config = [], ?callable $shouldContinue = null): Response
        {
            if (!$this->dataProcessor) throw new \RuntimeException('DataProcessor not set for ShopifyDriver');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $api = $this->getApi($config);
            $response = $api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }');
            $shop = $response['data']['shop'] ?? null;
            if (!is_array($shop) || empty($shop['id'])) throw new \UnexpectedValueException('Shopify shop identity is missing.');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $this->verifySelectedShop($shop, $config);
            ($this->dataProcessor)(ShopifyConvert::shopAccount($shop), 'account');
            ($this->dataProcessor)(ShopifyConvert::shopStore($shop), 'store');
            $shopId = (string)$shop['id'];
            $this->rootConnection($api,
                'query ShopifyOrders($cursor: String) { orders(first: 100, after: $cursor) { nodes { id legacyResourceId name createdAt updatedAt processedAt displayFinancialStatus displayFulfillmentStatus discountCodes refunds { id legacyResourceId createdAt totalRefundedSet { shopMoney { amount currencyCode } } } customer { id legacyResourceId email } subtotalPriceSet { shopMoney { amount currencyCode } } currentSubtotalPriceSet { shopMoney { amount currencyCode } } totalDiscountsSet { shopMoney { amount currencyCode } } currentTotalDiscountsSet { shopMoney { amount currencyCode } } currentTotalPriceSet { shopMoney { amount currencyCode } } customerJourneySummary { firstVisit { landingPage referrerUrl occurredAt utmParameters { source medium campaign content term } } lastVisit { landingPage referrerUrl occurredAt utmParameters { source medium campaign content term } } } } pageInfo { hasNextPage endCursor } } }',
                'orders',
                function (array $page) use ($api, $shopId, $shouldContinue): void {
                    if (!isset($page['nodes']) || !is_array($page['nodes'])) throw new \UnexpectedValueException('Invalid Shopify order page.');
                    foreach ($page['nodes'] as $order) {
                        if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                        if (!is_array($order) || !is_string($order['id'] ?? null) || $order['id'] === '') throw new \UnexpectedValueException('Shopify order identity is missing.');
                        $lines = [];
                        $api->graphqlPaginate(
                            'query ShopifyOrderLines($id: ID!, $cursor: String) { order(id: $id) { lineItems(first: 100, after: $cursor) { nodes { id name quantity sku originalUnitPriceSet { shopMoney { amount currencyCode } } originalTotalSet { shopMoney { amount currencyCode } } discountedTotalSet { shopMoney { amount currencyCode } } totalDiscountSet { shopMoney { amount currencyCode } } product { id legacyResourceId } variant { id legacyResourceId } } pageInfo { hasNextPage endCursor } } } }',
                            'order.lineItems',
                            static function (array $linePage) use (&$lines, $shouldContinue): void {
                                if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                                if (!isset($linePage['nodes']) || !is_array($linePage['nodes'])) throw new \UnexpectedValueException('Invalid Shopify order line-item page.');
                                array_push($lines, ...$linePage['nodes']);
                            }, ['id' => $order['id']], $shouldContinue,
                        );
                        $applications = [];
                        $api->graphqlPaginate(
                            'query ShopifyOrderDiscountApplications($id: ID!, $cursor: String) { order(id: $id) { discountApplications(first: 100, after: $cursor) { nodes { __typename index allocationMethod targetSelection targetType value { __typename ... on MoneyV2 { amount currencyCode } ... on PricingPercentageValue { percentage } } ... on DiscountCodeApplication { code } ... on AutomaticDiscountApplication { title } ... on ManualDiscountApplication { title description } ... on ScriptDiscountApplication { title } } pageInfo { hasNextPage endCursor } } } }',
                            'order.discountApplications',
                            static function (array $applicationPage) use (&$applications, $shouldContinue): void {
                                if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                                if (!is_array($applicationPage['nodes'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify discount application page.');
                                array_push($applications, ...$applicationPage['nodes']);
                            }, ['id' => $order['id']], $shouldContinue,
                        );
                        if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                        $order['lineItems'] = ['nodes' => $lines, 'pageInfo' => ['hasNextPage' => false]];
                        $order['discountApplications'] = ['nodes' => $applications, 'pageInfo' => ['hasNextPage' => false]];
                        $events = \Anibalealvarezs\ShopifyHubDriver\Conversions\ShopifyEventConvert::order($order, $shopId);
                        ($this->dataProcessor)(ShopifyConvert::graphqlOrders([$order], $shopId), 'order');
                        ($this->dataProcessor)($events, 'event');
                    }
                }, $config, $shouldContinue,
            );
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify accessible orders sync completed'], JSON_THROW_ON_ERROR));
        }

        /** Synchronize discounts and their redeem codes without truncating nested connections. */
        public function syncShopAndDiscounts(array $config = [], ?callable $shouldContinue = null): Response
        {
            if (!$this->dataProcessor) throw new \RuntimeException('DataProcessor not set for ShopifyDriver');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $api = $this->getApi($config);
            $shop = $api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }')['data']['shop'] ?? null;
            if (!is_array($shop) || empty($shop['id'])) throw new \UnexpectedValueException('Shopify shop identity is missing.');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $this->verifySelectedShop($shop, $config);
            ($this->dataProcessor)(ShopifyConvert::shopAccount($shop), 'account');
            ($this->dataProcessor)(ShopifyConvert::shopStore($shop), 'store');
            $fields = '... on DiscountCodeBasic { title createdAt status } ... on DiscountCodeBxgy { title createdAt status } ... on DiscountCodeFreeShipping { title createdAt status } ... on DiscountCodeApp { title createdAt status } ... on DiscountAutomaticBasic { title createdAt status } ... on DiscountAutomaticBxgy { title createdAt status } ... on DiscountAutomaticFreeShipping { title createdAt status } ... on DiscountAutomaticApp { title createdAt status }';
            $codeFields = '... on DiscountCodeBasic { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } ... on DiscountCodeBxgy { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } ... on DiscountCodeFreeShipping { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } } ... on DiscountCodeApp { codes(first: 100, after: $cursor) { nodes { id code } pageInfo { hasNextPage endCursor } } }';
            $api->graphqlPaginate('query ShopifyDiscounts($cursor: String) { discountNodes(first: 100, after: $cursor) { nodes { id discount { __typename '.$fields.' } } pageInfo { hasNextPage endCursor } } }', 'discountNodes',
                function (array $page) use ($api, $shop, $shouldContinue, $codeFields): void {
                    if (!is_array($page['nodes'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify discount page.');
                    foreach ($page['nodes'] as $node) {
                        if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                        if (!is_array($node) || !is_string($node['id'] ?? null) || $node['id'] === '' || !is_array($node['discount'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify discount.');
                        $details = $node['discount'];
                        $type = $details['__typename'] ?? '';
                        $automatic = in_array($type, ['DiscountAutomaticBasic', 'DiscountAutomaticBxgy', 'DiscountAutomaticFreeShipping', 'DiscountAutomaticApp'], true);
                        if (!$automatic && !in_array($type, ['DiscountCodeBasic', 'DiscountCodeBxgy', 'DiscountCodeFreeShipping', 'DiscountCodeApp'], true)) throw new \UnexpectedValueException('Unsupported Shopify discount type.');
                        $codes = [];
                        if (!$automatic) $api->graphqlPaginate('query ShopifyDiscountCodes($id: ID!, $cursor: String) { discountNode(id: $id) { discount { '.$codeFields.' } } }', 'discountNode.discount.codes',
                            static function (array $codePage) use (&$codes, $shouldContinue): void {
                                if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                                if (!is_array($codePage['nodes'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify redeem code page.');
                                array_push($codes, ...$codePage['nodes']);
                            }, ['id' => $node['id']], $shouldContinue);
                        if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                        $details['id'] = $node['id'];
                        $details['codes'] = ['nodes' => $codes, 'pageInfo' => ['hasNextPage' => false]];
                        ($this->dataProcessor)(ShopifyConvert::graphqlDiscounts([$details], (string)$shop['id']), 'price_rule');
                    }
                }, [], $shouldContinue);
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify discounts sync completed'], JSON_THROW_ON_ERROR));
        }

        /** Explicit partial commerce sync in dependency order; not a complete entities job. */
        public function syncShopCommerce(array $config = [], ?callable $shouldContinue = null): Response
        {
            $this->syncShopAndCustomers($config, $shouldContinue);
            $this->syncShopAndCollections($config, $shouldContinue);
            $this->syncShopAndProducts($config, $shouldContinue);
            $this->syncShopAndDiscounts($config, $shouldContinue);
            $this->syncShopAndOrders($config, $shouldContinue);
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify commerce sync completed'], JSON_THROW_ON_ERROR));
        }

        public function syncShopAndCollections(array $config = [], ?callable $shouldContinue = null): Response
        {
            if (!$this->dataProcessor) throw new \RuntimeException('DataProcessor not set for ShopifyDriver');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $api = $this->getApi($config);
            $shop = $api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }')['data']['shop'] ?? null;
            if (!is_array($shop) || empty($shop['id'])) throw new \UnexpectedValueException('Shopify shop identity is missing.');
            $this->verifySelectedShop($shop, $config);
            ($this->dataProcessor)(ShopifyConvert::shopAccount($shop), 'account');
            ($this->dataProcessor)(ShopifyConvert::shopStore($shop), 'store');
            $api->graphqlPaginate('query ShopifyCollections($cursor: String) { collections(first: 100, after: $cursor) { nodes { id title handle updatedAt ruleSet { appliedDisjunctively } } pageInfo { hasNextPage endCursor } } }', 'collections',
                function (array $page) use ($shop, $shouldContinue): void {
                    if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
                    if (!is_array($page['nodes'] ?? null)) throw new \UnexpectedValueException('Invalid Shopify collection page.');
                    $records = ShopifyConvert::graphqlCollections($page['nodes'], (string)$shop['id']);
                    if (!$records->isEmpty()) ($this->dataProcessor)($records, 'product_category');
                }, [], $shouldContinue);
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify collections sync completed'], JSON_THROW_ON_ERROR));
        }

        /** Explicit daily ShopifyQL sales sync; dates are inclusive and bounded. */
        public function syncDailySales(DateTime $start, DateTime $end, array $config = [], ?callable $shouldContinue = null): Response
        {
            if (!$this->dataProcessor) throw new \RuntimeException('DataProcessor not set for ShopifyDriver');
            $startDay = new \DateTimeImmutable($start->format('Y-m-d'));
            $endDay = new \DateTimeImmutable($end->format('Y-m-d'));
            if ($startDay > $endDay || $startDay->diff($endDay)->days > 365) throw new \InvalidArgumentException('Shopify daily sales intervals must be at most 366 days.');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $api = $this->getApi($config);
            $response = $api->graphql('query ShopifyShop { shop { id name createdAt myshopifyDomain primaryDomain { host } currencyCode } }');
            $shop = $response['data']['shop'] ?? null;
            if (!is_array($shop) || empty($shop['id']) || empty($shop['currencyCode'])) throw new \UnexpectedValueException('Shopify shop identity or currency is missing.');
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $this->verifySelectedShop($shop, $config);
            ($this->dataProcessor)(ShopifyConvert::shopAccount($shop), 'account');
            ($this->dataProcessor)(ShopifyConvert::shopStore($shop), 'store');
            $query = 'FROM sales SHOW orders, gross_sales, net_sales, total_sales TIMESERIES day SINCE '.$startDay->format('Y-m-d').' UNTIL '.$endDay->format('Y-m-d').' ORDER BY day ASC LIMIT 1000';
            $table = $api->shopifyqlQuery($query);
            if ($shouldContinue && !$shouldContinue()) throw new \RuntimeException('Shopify sync cancelled.');
            $metrics = ShopifyMetricConvert::dailySales($table, (string)$shop['id'], (string)$shop['currencyCode'], $startDay, $endDay);
            if ($metrics->count() > 0) ($this->dataProcessor)($metrics);
            return new Response(json_encode(['status' => 'success', 'message' => 'Shopify daily sales sync completed'], JSON_THROW_ON_ERROR));
        }

        public function sync(
            DateTime  $startDate,
            DateTime  $endDate,
            array     $config = [],
            ?callable $shouldContinue = null,
            ?callable $identityMapper = null
        ): Response

        {
            // Explicit opt-in jobs expose only the portion that is implemented; the
            // legacy default remains until complete entity and metric scheduling exists.
            $entity = $config['entity'] ?? null;
            if ($entity === 'shop_customers') return $this->syncShopAndCustomers($config, $shouldContinue);
            if ($entity === 'shop_products') return $this->syncShopAndProducts($config, $shouldContinue);
            if ($entity === 'shop_collections') return $this->syncShopAndCollections($config, $shouldContinue);
            if ($entity === 'shop_orders') return $this->syncShopAndOrders($config, $shouldContinue);
            if ($entity === 'shop_discounts') return $this->syncShopAndDiscounts($config, $shouldContinue);
            if ($entity === 'shop_commerce') return $this->syncShopCommerce($config, $shouldContinue);
            if ($entity === 'shop_daily_sales') return $this->syncDailySales($startDate, $endDate, $config, $shouldContinue);
            if ($entity === 'entities') return $this->syncEntities($config, $shouldContinue);
            if ($entity === null || $entity === 'metrics' || $entity === 'metric') return $this->syncMetrics($startDate, $endDate, $config, $shouldContinue);
            throw new \InvalidArgumentException('Unsupported Shopify sync entity.');
        }

        public function getApi(array $config = []): ShopifyApi
        {
            if (!empty($config['account_id'])) {
                $credentials = $this->resolveAccountCredentials($config);
                if (!$credentials || empty($credentials['access_token']) || empty($credentials['shop_name'])) throw new \RuntimeException('Credentials for the selected Shopify account are unavailable.');
                $domain = strtolower(trim((string)$credentials['shop_name']));
                if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $domain)) throw new \RuntimeException('Invalid Shopify account domain.');
                $defaultVersion = $this->authProvider instanceof \Anibalealvarezs\ShopifyHubDriver\Auth\ShopifyAuthProvider ? $this->authProvider->getVersion() : '2026-10';
                return new ShopifyApi(apiKey: $credentials['access_token'], shopName: substr($domain, 0, -strlen('.myshopify.com')), version: $credentials['version'] ?? $defaultVersion);
            }
            if ($this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface && $this->authProvider->getAccounts() !== []) {
                throw new \RuntimeException('Select a Shopify account before syncing.');
            }
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                throw new \Exception("Credentials not configured.");
            }

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
                    'enabled'             => false,
                    'cache_history_range' => '30 days',
                    'cache_aggregations'  => false,
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
                $config,
                $this->getConfigSchema()
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
        public static function getAssetPatterns(): array
        {
            return [
                'shopify_store' => [
                    'key'          => 'stores',
                    'prefix'       => 'sh:store',
                    'hostnames'    => ['myshopify.com'],
                    'url_id_regex' => null,
                    'category'     => [\Anibalealvarezs\ApiDriverCore\Enums\AssetCategory::IDENTITY],
                ]
            ];
        }

        /**
         * @return array{history_months: int, entities_sync: string|null, recent_cron_hour: int, recent_cron_minute: int}
         */
        public static function getInstanceRules(): array
        {
            return [
                'history_months'     => 6,
                'entities_sync'      => 'entities',
                'recent_cron_hour'   => 3,
                'recent_cron_minute' => 0,
            ];
        }

        /**
         * @inheritdoc
         */
        public function initializeEntities(array $config = []): array
        {
            return ['initialized' => 0, 'skipped' => 0];
        }

        /**
         * @inheritdoc
         */
        public function reset(string $mode = 'all', array $config = []): array
        {
            return ['cleared' => 0, 'mode' => $mode];
        }

        public function updateConfiguration(array $newData, array $currentConfig): array
        {
            $nested = isset($currentConfig['channels']['shopify']) && is_array($currentConfig['channels']['shopify']);
            $channel = $nested ? $currentConfig['channels']['shopify'] : $currentConfig;
            $assets = $newData['assets'] ?? null;
            unset($newData['assets']);
            $channel = array_replace_recursive($channel, $newData);
            if (isset($channel['accounts'])) {
                if (!is_array($channel['accounts'])) throw new \InvalidArgumentException('Shopify accounts must be an account map.');
                foreach ($channel['accounts'] as $id => &$account) {
                    if ((string)$id === '' || !is_array($account)) throw new \InvalidArgumentException('Invalid Shopify account configuration.');
                    if (isset($account['shop_name'])) {
                        $domain = strtolower(trim((string)$account['shop_name']));
                        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $domain)) throw new \InvalidArgumentException('Invalid Shopify account domain.');
                        $account['shop_name'] = $domain;
                    }
                }
                unset($account);
            }
            if (is_array($assets)) {
                foreach ($assets as $type => $selection) {
                    $channel[$type] = $selection;
                }
            }
            if ($nested) {
                $currentConfig['channels']['shopify'] = $channel;
                return $currentConfig;
            }
            return $channel;
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
                'end'   => 'createdAtMax'
            ];
        }
    }
