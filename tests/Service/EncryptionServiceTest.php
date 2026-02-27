<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EncryptionService(base64_encode(random_bytes(32)), 1);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $payload = $this->service->encrypt('Hola mundo cifrado');
        $plain = $this->service->decrypt($payload['ciphertext'], $payload['nonce']);

        self::assertSame('Hola mundo cifrado', $plain);
    }

    public function testBlindIndexIsStableForSameEmail(): void
    {
        $indexA = $this->service->emailBlindIndex('User@Example.com');
        $indexB = $this->service->emailBlindIndex(' user@example.com ');

        self::assertSame($indexA, $indexB);
    }
}
