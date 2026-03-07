<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Service\EncryptionService;
use App\Tests\Support\DatabaseResetTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ChatEnhancementsTest extends WebTestCase
{
    use DatabaseResetTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    public function testProfileCanBeUpdatedAndRead(): void
    {
        $user = $this->createActiveUser('perfil@example.com', 'Perfil Uno');
        $this->client->loginUser($user);

        $tmpFile = tempnam(sys_get_temp_dir(), 'avatar-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAusB9YhIwr0AAAAASUVORK5CYII='));
        $avatar = new UploadedFile($tmpFile, 'avatar.png', 'image/png', null, true);

        $this->client->request('POST', '/api/profile', [
            '_csrf_token' => 'test-token',
            'displayName' => 'Perfil Nuevo',
            'statusText' => 'Disponible',
        ], [
            'avatar' => $avatar,
        ]);

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertSame('Perfil Nuevo', $payload['profile']['displayName'] ?? null);
        self::assertSame('Disponible', $payload['profile']['statusText'] ?? null);
        self::assertTrue((bool) ($payload['profile']['hasAvatar'] ?? false));

        $this->client->request('GET', '/api/profile');
        self::assertResponseStatusCodeSame(200);
        $profilePayload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Perfil Nuevo', $profilePayload['profile']['displayName'] ?? null);
    }

    public function testProfileRejectsInvalidAvatarType(): void
    {
        $user = $this->createActiveUser('invalid-avatar@example.com', 'Perfil Dos');
        $this->client->loginUser($user);

        $tmpFile = tempnam(sys_get_temp_dir(), 'avatar-bad-');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'not-an-image');
        $avatar = new UploadedFile($tmpFile, 'avatar.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/profile', [
            '_csrf_token' => 'test-token',
            'displayName' => 'Perfil Dos',
            'statusText' => 'Estado',
        ], [
            'avatar' => $avatar,
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAiDedicatedChatReturnsAiMessage(): void
    {
        $user = $this->createActiveUser('chat-ai@example.com', 'Usuario IA');
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/users');
        self::assertResponseStatusCodeSame(200);
        $usersPayload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $bot = null;
        foreach (($usersPayload['users'] ?? []) as $entry) {
            if (($entry['isBot'] ?? false) === true) {
                $bot = $entry;
                break;
            }
        }

        self::assertNotNull($bot);

        $this->client->request('POST', '/api/chats', [
            '_csrf_token' => 'test-token',
            'targetUserId' => (string) $bot['id'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $chatPayload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $chatId = (int) ($chatPayload['chatId'] ?? 0);
        self::assertGreaterThan(0, $chatId);

        $this->client->request('POST', '/api/chats/'.$chatId.'/messages', [
            '_csrf_token' => 'test-token',
            'text' => 'Hola IA',
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('ok', $payload['status'] ?? null);
        self::assertNotNull($payload['aiMessage'] ?? null);
        self::assertStringContainsString('Nubecita IA', (string) ($payload['aiMessage']['text'] ?? ''));
    }

    public function testGroupCreateAndLeaveFlow(): void
    {
        $owner = $this->createActiveUser('owner@example.com', 'Owner');
        $memberA = $this->createActiveUser('member-a@example.com', 'Member A');
        $memberB = $this->createActiveUser('member-b@example.com', 'Member B');

        $this->client->loginUser($owner);

        $this->client->request(
            'POST',
            '/api/chats/groups',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Grupo QA',
                'memberIds' => [(int) $memberA->getId()],
                '_csrf_token' => 'test-token',
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);
        $createPayload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $chatId = (int) ($createPayload['chatId'] ?? 0);
        self::assertGreaterThan(0, $chatId);

        $this->client->request(
            'POST',
            '/api/chats/'.$chatId.'/members',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'test-token'],
            json_encode(['memberIds' => [(int) $memberB->getId()]], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/chats/'.$chatId.'/members');
        self::assertResponseStatusCodeSame(200);
        $membersPayload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(3, $membersPayload['members'] ?? []);

        $this->client->loginUser($memberA);
        $this->client->request('POST', '/api/chats/'.$chatId.'/leave', [
            '_csrf_token' => 'test-token',
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    private function createActiveUser(string $email, string $displayName): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var EncryptionService $encryptionService */
        $encryptionService = static::getContainer()->get(EncryptionService::class);

        $user = (new User())
            ->setDisplayName($displayName)
            ->setEmailHash($encryptionService->emailBlindIndex($email))
            ->setEmailCiphertext($encryptionService->encryptCombined($email))
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true)
            ->setPassword((string) password_hash('Password1234', PASSWORD_BCRYPT));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
