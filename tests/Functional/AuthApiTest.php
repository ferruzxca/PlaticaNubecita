<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\DatabaseResetTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class AuthApiTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testRequestRegistrationLinkReturnsOk(): void
    {
        $this->client->request('POST', '/api/auth/request-registration-link', [
            'email' => 'nuevo.usuario@example.com',
            '_csrf_token' => 'test-token',
        ]);

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('ok', $payload['status'] ?? null);
    }

    public function testLoginWithInvalidCredentialsFails(): void
    {
        $this->client->request('POST', '/api/auth/login', [
            'email' => 'nadie@example.com',
            'password' => 'Password1234',
            '_csrf_token' => 'test-token',
        ]);

        self::assertResponseStatusCodeSame(401);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('error', $payload['status'] ?? null);
    }
}
