# Shopify credential storage

File-backed authentication requires PHP Sodium and a stable 32-byte encryption key. Supply the binary key as the second ShopifyAuthProvider constructor argument, or provide its base64 encoding through `SHOPIFY_CREDENTIAL_ENCRYPTION_KEY` in the host's secret environment. Keep the key separate from channel configuration, token files, source control and logs.

The host factory can pass `['token_path' => '/private/path/shopify.json']`. The provider loads that file and uses authenticated Sodium secretbox encryption on writes. It never generates a replacement key automatically. Back up the key securely: losing it makes the encrypted credentials unreadable.

Legacy plaintext token files remain readable. A subsequent save with the key supplied migrates the file to the encrypted envelope. Back up and verify a migration before removing the original. File writes without a key fail. In-memory credential arrays do not require file encryption.

Key rotation requires reading with the old key and deliberately rewriting with the new key; no automated rotation workflow is implemented yet. Deployments must enable Sodium and inject the same key into every process that reads the file.

Run the encryption regression suite locally with `php -d extension=sodium vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php tests`.
