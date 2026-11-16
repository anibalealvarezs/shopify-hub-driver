<?php

declare(strict_types=1);

namespace Anibalealvarezs\ShopifyHubDriver\Auth;

use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;

class ShopifyAuthProvider extends BaseAuthProvider implements \Anibalealvarezs\ApiDriverCore\Interfaces\MultiAccountAuthProviderInterface
{
    private ?string $encryptionKey;
    private ?string $loadedDigest = null;

    public function __construct(array|string $configOrPath = '', ?string $encryptionKey = null)
    {
        if ($encryptionKey === null) {
            $encodedKey = getenv('SHOPIFY_CREDENTIAL_ENCRYPTION_KEY');
            if ($encodedKey !== false && $encodedKey !== '') {
                $decodedKey = base64_decode($encodedKey, true);
                if ($decodedKey === false) throw new \InvalidArgumentException('Invalid Shopify credential encryption key encoding.');
                $encryptionKey = $decodedKey;
            }
        }
        if ($encryptionKey !== null && (!function_exists('sodium_crypto_secretbox') || strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) {
            throw new \InvalidArgumentException('A valid Shopify credential encryption key is required.');
        }
        $this->encryptionKey = $encryptionKey;
        if (is_array($configOrPath) && isset($configOrPath['token_path'])) {
            if (!is_string($configOrPath['token_path']) || $configOrPath['token_path'] === '') throw new \InvalidArgumentException('Invalid Shopify token file path.');
            $configOrPath = $configOrPath['token_path'];
        } elseif ($configOrPath === '' || (is_array($configOrPath) && $configOrPath === [])) {
            $envPath = $_ENV['SHOPIFY_TOKEN_PATH'] ?? getenv('SHOPIFY_TOKEN_PATH');
            if (is_string($envPath) && $envPath !== '') {
                $configOrPath = $envPath;
            }
        }
        parent::__construct($configOrPath);
    }

    protected function load(): void
    {
        if ($this->filePath === '' || !is_file($this->filePath)) return;
        $contents = file_get_contents($this->filePath);
        if ($contents === false) throw new \RuntimeException('Unable to read Shopify credentials.');
        $this->loadedDigest = hash('sha256', $contents);
        if ($contents === '') return;
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid Shopify credential file.');
        }
        if (!is_array($decoded)) throw new \RuntimeException('Invalid Shopify credential file.');
        $this->data = $decoded;
        if (($this->data['format'] ?? null) !== 'shopify-secretbox-v1') return;
        if ($this->encryptionKey === null) throw new \RuntimeException('Shopify credential decryption key is required.');
        $payload = base64_decode((string)($this->data['payload'] ?? ''), true);
        if ($payload === false || strlen($payload) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new \RuntimeException('Invalid encrypted Shopify credentials.');
        $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->encryptionKey);
        if ($plain === false) throw new \RuntimeException('Unable to decrypt Shopify credentials.');
        $this->data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function save(): void
    {
        if ($this->filePath === '') return;
        if ($this->encryptionKey === null) throw new \RuntimeException('Shopify credential encryption key is required for file persistence.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = sodium_crypto_secretbox(json_encode($this->data, JSON_THROW_ON_ERROR), $nonce, $this->encryptionKey);
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Unable to create Shopify credential directory.');
        $lock = fopen($this->filePath.'.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Unable to lock Shopify credentials.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Unable to lock Shopify credentials.');
            $current = is_file($this->filePath) ? hash_file('sha256', $this->filePath) : null;
            if ($current !== $this->loadedDigest) throw new \RuntimeException('Shopify credentials changed concurrently; reload before updating.');
        $temporary = tempnam($directory, '.shopify-');
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
            throw new \RuntimeException('Unable to stage Shopify credentials.');
        }
        try {
            $encoded = json_encode(['format' => 'shopify-secretbox-v1', 'payload' => base64_encode($nonce.$payload)], JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) throw new \RuntimeException('Unable to write Shopify credentials.');
            if (!@rename($temporary, $this->filePath)) throw new \RuntimeException('Unable to replace Shopify credentials.');
            $this->loadedDigest = hash('sha256', $encoded);
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function getAccounts(): array
    {
        $result = [];
        foreach ($this->data['accounts'] ?? [] as $id => $credentials) {
            $result[(string)$id] = ['account_id' => (string)$id, 'name' => $credentials['name'] ?? null, 'is_valid' => !empty($credentials['access_token']) && !empty($credentials['shop_name'])];
        }
        return $result;
    }

    public function getCredentialsForAccount(string $accountId): ?array
    {
        return $this->data['accounts'][$accountId] ?? null;
    }

    public function storeAccountCredentials(string $accountId, array $credentials): void
    {
        $credentials = array_replace($this->data['accounts'][$accountId] ?? [], $credentials);
        $domain = strtolower(trim((string)($credentials['shop_name'] ?? '')));
        if ($accountId === '' || !preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $domain) || empty($credentials['access_token'])) {
            throw new \InvalidArgumentException('Account identity, myshopify domain and access token are required.');
        }
        $credentials['shop_name'] = $domain;
        $this->data['accounts'][$accountId] = $credentials;
        $this->save();
    }

    public function removeAccountCredentials(string $accountId): void
    {
        unset($this->data['accounts'][$accountId]);
        $this->save();
    }

    public function updateCredentials(array $credentials): void
    {
        if (isset($credentials['account_id'])) {
            $accountId = (string)$credentials['account_id'];
            unset($credentials['account_id']);
            $this->storeAccountCredentials($accountId, $credentials);
            return;
        }
        parent::updateCredentials($credentials);
    }

    public function getAccessToken(): string
    {
        return $this->data['shopify_auth']['access_token'] ?? "";
    }

    public function getUserId(): string
    {
        return $this->data['shopify_auth']['shop_name'] ?? "";
    }

    public function getShopName(): string
    {
        return $this->data['shopify_auth']['shop_name'] ?? "";
    }

    public function getVersion(): string
    {
        return $this->data['shopify_auth']['version'] ?? "2024-04";
    }

    public function setAccessToken(string $token, string $shopName = "", string $version = ""): void
    {
        if (!isset($this->data['shopify_auth'])) {
            $this->data['shopify_auth'] = [];
        }
        $this->data['shopify_auth']['access_token'] = $token;
        if ($shopName) {
            $this->data['shopify_auth']['shop_name'] = $shopName;
        }
        if ($version) {
            $this->data['shopify_auth']['version'] = $version;
        }
        $this->save();
    }

    public function hasCredentials(): bool
    {
        if ($this->getAccessToken() !== '') return true;
        foreach ($this->getAccounts() as $account) {
            if ($account['is_valid']) return true;
        }
        return false;
    }

    public function isValid(): bool
    {
        return $this->hasCredentials();
    }
}
