<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\RegistrationToken;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use App\Service\TokenService;
use App\Tests\Support\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testRegisterWithValidTokenCreatesUserAndLogsIn(): void
    {
        /** @var EncryptionService $encryptionService */
        $encryptionService = static::getContainer()->get(EncryptionService::class);
        /** @var TokenService $tokenService */
        $tokenService = static::getContainer()->get(TokenService::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $email = 'registro.prueba@example.com';
        $plainToken = 'test-register-token-123';

        $registrationToken = (new RegistrationToken())
            ->setEmailHash($encryptionService->emailBlindIndex($email))
            ->setEmailCiphertext($encryptionService->encryptCombined($email))
            ->setTokenHash($tokenService->hashToken($plainToken))
            ->setExpiresAt((new \DateTimeImmutable())->modify('+30 minutes'));

        $entityManager->persist($registrationToken);
        $entityManager->flush();

        $this->client->request('POST', '/api/auth/register', [
            '_csrf_token' => 'test-token',
            'token' => $plainToken,
            'displayName' => 'Usuario Test',
            'password' => 'Password1234',
        ]);

        self::assertResponseStatusCodeSame(201);

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        self::assertNotNull($userRepository->findOneActiveByEmailHash($encryptionService->emailBlindIndex($email)));

        $this->client->request('GET', '/chat');
        self::assertResponseIsSuccessful();
    }
}
