<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class EncryptionService
{
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private string $encryptionKey;
    private string $blindIndexKey;
    private int $keyVersion;

    public function __construct(
        #[Autowire('%env(APP_ENCRYPTION_KEY)%')]
        string $appEncryptionKey,
        #[Autowire('%env(int:APP_ENCRYPTION_KEY_VERSION)%')]
        int $keyVersion = 1,
    ) {
        $decoded = base64_decode($appEncryptionKey, true);
        if (false === $decoded || '' === $decoded) {
            throw new \InvalidArgumentException('APP_ENCRYPTION_KEY debe estar en base64.');
        }

        if (strlen($decoded) < self::KEY_BYTES) {
            $decoded = str_pad($decoded, self::KEY_BYTES, "\0");
        }

        $this->encryptionKey = substr($decoded, 0, self::KEY_BYTES);
        $this->blindIndexKey = hash_hmac('sha256', 'email-blind-index', $this->encryptionKey, true);
        $this->keyVersion = max(1, $keyVersion);
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function emailBlindIndex(string $email): string
    {
        $normalized = $this->normalizeEmail($email);

        return hash_hmac('sha256', $normalized, $this->blindIndexKey);
    }

    /**
     * @return array{ciphertext: string, nonce: string, key_version: int}
     */
    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey);

        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'key_version' => $this->keyVersion,
        ];
    }

    public function decrypt(string $ciphertext, string $nonce): string
    {
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);
        if (false === $plaintext) {
            throw new \RuntimeException('No se pudo descifrar el contenido.');
        }

        return $plaintext;
    }

    public function encryptCombined(string $plaintext): string
    {
        $payload = $this->encrypt($plaintext);

        return $payload['nonce'].$payload['ciphertext'];
    }

    public function decryptCombined(string $payload): string
    {
        if (strlen($payload) < self::NONCE_BYTES + 1) {
            throw new \RuntimeException('Payload cifrado inválido.');
        }

        $nonce = substr($payload, 0, self::NONCE_BYTES);
        $ciphertext = substr($payload, self::NONCE_BYTES);

        return $this->decrypt($ciphertext, $nonce);
    }

    public function getKeyVersion(): int
    {
        return $this->keyVersion;
    }
}
