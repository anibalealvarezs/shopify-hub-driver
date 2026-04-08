<?php

namespace Anibalealvarezs\ShopifyHubDriver\Auth;

use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;

class ShopifyAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private ?string $tokenPath;

    public function __construct(?string $tokenPath = null, ?array $config = [])
    {
        $this->tokenPath = $tokenPath;
        if ($this->tokenPath && file_exists($this->tokenPath)) {
            $this->loadCredentials();
        }

        // Fallback to provided config or ENV
        if (empty($this->credentials['access_token'])) {
            $this->credentials['access_token'] = $config['shopify_api_key'] ?? $_ENV['SHOPIFY_API_KEY'] ?? '';
            $this->credentials['shop_name'] = $config['shopify_shop_name'] ?? $_ENV['SHOPIFY_SHOP_NAME'] ?? '';
            $this->credentials['version'] = $config['shopify_last_stable_revision'] ?? $_ENV['SHOPIFY_API_VERSION'] ?? '2024-01';
        }
    }

    private function loadCredentials(): void
    {
        if ($this->tokenPath && file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['shopify_auth'] ?? [];
        }
    }

    public function getAccessToken(): string
    {
        return $this->credentials['access_token'] ?? '';
    }

    public function getShopName(): string
    {
        return $this->credentials['shop_name'] ?? '';
    }

    public function getVersion(): string
    {
        return $this->credentials['version'] ?? '2024-01';
    }

    public function isValid(): bool
    {
        return !empty($this->getAccessToken()) && !empty($this->getShopName());
    }

    public function isExpired(): bool
    {
        return false;
    }

    public function refresh(): bool
    {
        return false;
    }

    public function getScopes(): array
    {
        return $this->credentials['scopes'] ?? [];
    }

    public function setAuthProvider(AuthProviderInterface $provider): void 
    {
        // Not needed
    }

    public function setAccessToken(string $token): void
    {
        $this->credentials['access_token'] = $token;
        if ($this->tokenPath) {
            $this->saveCredentials();
        }
    }

    private function saveCredentials(): void
    {
        if (!$this->tokenPath) return;

        $tokens = file_exists($this->tokenPath) ? (json_decode(file_get_contents($this->tokenPath), true) ?? []) : [];
        $tokens['shopify_auth'] = array_merge($tokens['shopify_auth'] ?? [], $this->credentials);
        $tokens['shopify_auth']['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
