<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TokenService;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    public function testTokenGenerationAndHashing(): void
    {
        $service = new TokenService('clave-hash-prueba');
        $token = $service->generateToken();

        self::assertNotEmpty($token);
        self::assertSame(64, strlen($service->hashToken(str_repeat('a', 10))));
    }
}
