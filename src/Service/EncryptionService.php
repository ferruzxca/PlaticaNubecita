<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class EncryptionService
{
    private const NONCE_BYTES = 24;
    private const KEY_BYTES = 32;
    private const OPENSSL_IV_BYTES = 12;
    private const OPENSSL_TAG_BYTES = 16;

    private string $encryptionKey;
    private string $blindIndexKey;
    private int $keyVersion;
    private bool $useSodium;

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
        $this->useSodium = function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open');

        if (!$this->useSodium && !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException('No hay backend de cifrado disponible. Activa ext-sodium o aes-256-gcm en OpenSSL.');
        }
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
        if ($this->useSodium) {
            $nonce = random_bytes(self::NONCE_BYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey);
        } else {
            $iv = random_bytes(self::OPENSSL_IV_BYTES);
            $cipherRaw = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, '', self::OPENSSL_TAG_BYTES);
            if (false === $cipherRaw || !is_string($tag)) {
                throw new \RuntimeException('Fallo al cifrar con OpenSSL.');
            }

            $nonce = str_pad($iv, self::NONCE_BYTES, "\0");
            $ciphertext = $tag.$cipherRaw;
        }

        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'key_version' => $this->keyVersion,
        ];
    }

    public function decrypt(string $ciphertext, string $nonce): string
    {
        if ($this->useSodium) {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);
            if (false === $plaintext) {
                throw new \RuntimeException('No se pudo descifrar el contenido.');
            }

            return $plaintext;
        }

        if (strlen($ciphertext) <= self::OPENSSL_TAG_BYTES) {
            throw new \RuntimeException('Ciphertext inválido para OpenSSL.');
        }

        $iv = substr($nonce, 0, self::OPENSSL_IV_BYTES);
        $tag = substr($ciphertext, 0, self::OPENSSL_TAG_BYTES);
        $raw = substr($ciphertext, self::OPENSSL_TAG_BYTES);

        $plaintext = openssl_decrypt($raw, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $plaintext) {
            throw new \RuntimeException('No se pudo descifrar el contenido.');
        }

        return $plaintext;
    }

    public function encryptCombined(string $plaintext): string
    {
        if ($this->useSodium) {
            $payload = $this->encrypt($plaintext);

            return 'S'.$payload['nonce'].$payload['ciphertext'];
        }

        $iv = random_bytes(self::OPENSSL_IV_BYTES);
        $cipherRaw = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, '', self::OPENSSL_TAG_BYTES);
        if (false === $cipherRaw || !is_string($tag)) {
            throw new \RuntimeException('Fallo al cifrar payload combinado.');
        }

        return 'O'.$iv.$tag.$cipherRaw;
    }

    public function decryptCombined(string $payload): string
    {
        if ('' === $payload) {
            throw new \RuntimeException('Payload cifrado inválido.');
        }

        $mode = $payload[0];
        if ('S' === $mode) {
            if (strlen($payload) < 1 + self::NONCE_BYTES + 1) {
                throw new \RuntimeException('Payload cifrado inválido (sodium).');
            }

            $nonce = substr($payload, 1, self::NONCE_BYTES);
            $ciphertext = substr($payload, 1 + self::NONCE_BYTES);

            return sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey)
                ?: throw new \RuntimeException('No se pudo descifrar payload sodium.');
        }

        if ('O' === $mode) {
            if (strlen($payload) < 1 + self::OPENSSL_IV_BYTES + self::OPENSSL_TAG_BYTES + 1) {
                throw new \RuntimeException('Payload cifrado inválido (openssl).');
            }

            $offset = 1;
            $iv = substr($payload, $offset, self::OPENSSL_IV_BYTES);
            $offset += self::OPENSSL_IV_BYTES;
            $tag = substr($payload, $offset, self::OPENSSL_TAG_BYTES);
            $offset += self::OPENSSL_TAG_BYTES;
            $raw = substr($payload, $offset);

            $plaintext = openssl_decrypt($raw, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
            if (false === $plaintext) {
                throw new \RuntimeException('No se pudo descifrar payload openssl.');
            }

            return $plaintext;
        }

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
