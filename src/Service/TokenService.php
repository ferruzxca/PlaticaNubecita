<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TokenService
{
    private string $tokenHashKey;

    public function __construct(
        #[Autowire('%env(APP_TOKEN_HASH_KEY)%')]
        string $tokenHashKey,
    ) {
        if ('' === trim($tokenHashKey)) {
            throw new \InvalidArgumentException('APP_TOKEN_HASH_KEY no puede estar vacío.');
        }

        $this->tokenHashKey = $tokenHashKey;
    }

    public function generateToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, $this->tokenHashKey);
    }
}
