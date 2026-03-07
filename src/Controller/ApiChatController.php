<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Chat;
use App\Entity\ChatParticipant;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\ChatParticipantRepository;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\AiChatService;
use App\Service\BotUserService;
use App\Service\ChatService;
use App\Service\EncryptionService;
use App\Service\UploadPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api')]
class ApiChatController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ChatRepository $chatRepository,
        private readonly ChatParticipantRepository $chatParticipantRepository,
        private readonly MessageRepository $messageRepository,
        private readonly AttachmentRepository $attachmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly ChatService $chatService,
        private readonly UploadPolicy $uploadPolicy,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly BotUserService $botUserService,
        private readonly AiChatService $aiChatService,
        #[Autowire(service: 'limiter.ai_chat')]
        private readonly RateLimiterFactory $aiLimiter,
    ) {
    }

    #[Route('/users', name: 'api_users', methods: ['GET'])]
    public function users(): JsonResponse
    {
        $this->botUserService->ensureBotUser();

        $currentUser = $this->currentUser();
        $users = $this->userRepository->findActiveUsersExcept((int) $currentUser->getId());

        $payload = array_map(fn (User $user): array => $this->serializeDirectoryUser($user), $users);

        return new JsonResponse(['status' => 'ok', 'users' => $payload]);
    }

    #[Route('/chats', name: 'api_chats', methods: ['GET'])]
    public function chats(): JsonResponse
    {
        $botUser = $this->botUserService->ensureBotUser();

        $currentUser = $this->currentUser();
        if ($botUser->getId() !== $currentUser->getId()) {
            $pairHash = $this->chatService->buildPairHash($currentUser, $botUser);
            $existingBotChat = $this->chatRepository->findOneByPairHash($pairHash);
            if (!$existingBotChat instanceof Chat) {
                $existingBotChat = $this->chatService->createDirectChat($currentUser, $botUser);
                $this->entityManager->persist($existingBotChat);
                $this->entityManager->flush();
            }
        }

        $participants = $this->chatParticipantRepository->findByUser($currentUser);

        $chats = [];
        foreach ($participants as $participant) {
            $chat = $participant->getChat();

            $isGroup = 'group' === $chat->getType();
            $peer = null;
            $name = null;
            $status = null;
            $avatarUrl = null;
            $isAiChat = false;
            $participantCount = count($chat->getParticipants());

            if ($isGroup) {
                $name = $this->chatService->decryptGroupName($chat, $this->encryptionService) ?? 'Grupo sin nombre';
            } else {
                $peer = $this->resolvePeer($chat, $currentUser);
                if (!$peer instanceof User) {
                    continue;
                }

                $name = $peer->getDisplayName();
                $status = $this->chatService->decryptUserStatus($peer, $this->encryptionService);
                $avatarUrl = $peer->hasAvatar() ? '/api/profile/avatar/'.(int) $peer->getId() : null;
                $isAiChat = $peer->isBot();
            }

            $lastMessage = $this->messageRepository->findLastByChat($chat);
            $lastText = null;
            $lastAt = null;
            if ($lastMessage instanceof Message) {
                try {
                    $cipher = $lastMessage->getCiphertext();
                    if (null !== $cipher && null !== $lastMessage->getNonce()) {
                        $lastText = $this->encryptionService->decrypt($cipher, $lastMessage->getNonce());
                    } elseif ($lastMessage->getAttachments()->count() > 0) {
                        $lastText = '[Adjunto]';
                    }
                } catch (\Throwable) {
                    $lastText = '[Mensaje no disponible]';
                }
                $lastAt = $lastMessage->getCreatedAt()->format(DATE_ATOM);
            }

            $chats[] = [
                'chatId' => (int) $chat->getId(),
                'type' => $chat->getType(),
                'name' => $name,
                'status' => $status,
                'avatarUrl' => $avatarUrl,
                'isAiChat' => $isAiChat,
                'participantCount' => $participantCount,
                'canManage' => $isGroup && $participant->isAdmin(),
                'peer' => $peer instanceof User ? $this->serializeDirectoryUser($peer) : null,
                'lastMessage' => $lastText,
                'lastAt' => $lastAt,
            ];
        }

        return new JsonResponse(['status' => 'ok', 'chats' => $chats]);
    }

    #[Route('/chats', name: 'api_chats_create', methods: ['POST'])]
    public function createChat(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_create')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $this->botUserService->ensureBotUser();

        $currentUser = $this->currentUser();
        $targetUserId = (int) ($this->input($request, 'targetUserId') ?? 0);

        if ($targetUserId <= 0 || $targetUserId === $currentUser->getId()) {
            return $this->jsonError('targetUserId inválido.', Response::HTTP_BAD_REQUEST);
        }

        $targetUser = $this->userRepository->find($targetUserId);
        if (!$targetUser instanceof User || !$targetUser->isActive()) {
            return $this->jsonError('Usuario destino no disponible.', Response::HTTP_NOT_FOUND);
        }

        $pairHash = $this->chatService->buildPairHash($currentUser, $targetUser);
        $chat = $this->chatRepository->findOneByPairHash($pairHash);

        if (!$chat instanceof Chat) {
            $chat = $this->chatService->createDirectChat($currentUser, $targetUser);
            $this->entityManager->persist($chat);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'ok', 'chatId' => (int) $chat->getId()]);
    }

    #[Route('/chats/groups', name: 'api_chats_groups_create', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_group_create')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $currentUser = $this->currentUser();
        $groupName = trim((string) ($this->input($request, 'name') ?? ''));
        if (mb_strlen($groupName) < 3 || mb_strlen($groupName) > 80) {
            return $this->jsonError('El nombre del grupo debe tener entre 3 y 80 caracteres.', Response::HTTP_BAD_REQUEST);
        }

        $memberIds = array_values(array_unique(array_filter(array_map('intval', $this->inputArray($request, 'memberIds')), static fn (int $id): bool => $id > 0)));
        $memberIds = array_values(array_filter($memberIds, fn (int $id): bool => $id !== (int) $currentUser->getId()));

        if ([] === $memberIds) {
            return $this->jsonError('Selecciona al menos un miembro para crear el grupo.', Response::HTTP_BAD_REQUEST);
        }

        if (count($memberIds) + 1 > 50) {
            return $this->jsonError('El grupo excede el límite de 50 miembros.', Response::HTTP_BAD_REQUEST);
        }

        $members = array_values(array_filter(
            $this->userRepository->findActiveUsersByIds($memberIds),
            static fn (User $user): bool => !$user->isBot(),
        ));

        if ([] === $members) {
            return $this->jsonError('No se encontraron miembros válidos.', Response::HTTP_BAD_REQUEST);
        }

        $chat = $this->chatService->createGroupChat($currentUser, $members, $groupName, $this->encryptionService);
        $this->entityManager->persist($chat);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok', 'chatId' => (int) $chat->getId()], Response::HTTP_CREATED);
    }

    #[Route('/chats/{chatId}/group', name: 'api_chats_group_rename', methods: ['PATCH'])]
    public function renameGroup(int $chatId, Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_group_manage')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $currentUser = $this->currentUser();
        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || 'group' !== $chat->getType()) {
            return $this->jsonError('Grupo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $participant = $this->chatParticipantRepository->findOneByChatAndUser($chatId, (int) $currentUser->getId());
        if (!$participant instanceof ChatParticipant || !$participant->isAdmin()) {
            return $this->jsonError('Solo un admin puede renombrar el grupo.', Response::HTTP_FORBIDDEN);
        }

        $groupName = trim((string) ($this->input($request, 'name') ?? ''));
        if (mb_strlen($groupName) < 3 || mb_strlen($groupName) > 80) {
            return $this->jsonError('El nombre del grupo debe tener entre 3 y 80 caracteres.', Response::HTTP_BAD_REQUEST);
        }

        $encryptedName = $this->encryptionService->encrypt($groupName);
        $chat->setNameCiphertext($encryptedName['ciphertext'])->setNameNonce($encryptedName['nonce']);
        $this->entityManager->persist($chat);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/chats/{chatId}/members', name: 'api_chats_members_list', methods: ['GET'])]
    public function listMembers(int $chatId): JsonResponse
    {
        $currentUser = $this->currentUser();
        if (!$this->chatParticipantRepository->isUserInChat($chatId, (int) $currentUser->getId())) {
            return $this->jsonError('No tienes acceso a este chat.', Response::HTTP_FORBIDDEN);
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || 'group' !== $chat->getType()) {
            return $this->jsonError('Grupo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $members = $this->chatParticipantRepository->findByChatOrdered($chatId);

        return new JsonResponse([
            'status' => 'ok',
            'members' => array_map(fn (ChatParticipant $member): array => [
                'userId' => (int) $member->getUser()->getId(),
                'displayName' => $member->getUser()->getDisplayName(),
                'role' => $member->getRole(),
                'status' => $this->chatService->decryptUserStatus($member->getUser(), $this->encryptionService),
                'avatarUrl' => $member->getUser()->hasAvatar() ? '/api/profile/avatar/'.(int) $member->getUser()->getId() : null,
                'isCurrentUser' => $member->getUser()->getId() === $currentUser->getId(),
                'joinedAt' => $member->getJoinedAt()->format(DATE_ATOM),
            ], $members),
        ]);
    }

    #[Route('/chats/{chatId}/members', name: 'api_chats_members_add', methods: ['POST'])]
    public function addMembers(int $chatId, Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_group_manage')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $currentUser = $this->currentUser();
        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || 'group' !== $chat->getType()) {
            return $this->jsonError('Grupo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $participant = $this->chatParticipantRepository->findOneByChatAndUser($chatId, (int) $currentUser->getId());
        if (!$participant instanceof ChatParticipant || !$participant->isAdmin()) {
            return $this->jsonError('Solo un admin puede agregar miembros.', Response::HTTP_FORBIDDEN);
        }

        $memberIds = array_values(array_unique(array_filter(array_map('intval', $this->inputArray($request, 'memberIds')), static fn (int $id): bool => $id > 0)));
        if ([] === $memberIds) {
            return $this->jsonError('Debes enviar memberIds.', Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->chatParticipantRepository->findByChatOrdered($chatId);
        $existingUserIds = array_map(static fn (ChatParticipant $cp): int => (int) $cp->getUser()->getId(), $existing);

        $toAddIds = array_values(array_filter($memberIds, static fn (int $id): bool => !in_array($id, $existingUserIds, true)));
        if ([] === $toAddIds) {
            return new JsonResponse(['status' => 'ok', 'added' => []]);
        }

        if (count($existingUserIds) + count($toAddIds) > 50) {
            return $this->jsonError('El grupo excede el límite de 50 miembros.', Response::HTTP_BAD_REQUEST);
        }

        $users = array_values(array_filter(
            $this->userRepository->findActiveUsersByIds($toAddIds),
            static fn (User $user): bool => !$user->isBot(),
        ));

        foreach ($users as $user) {
            $chat->addParticipant((new ChatParticipant())->setUser($user)->setRole('member'));
        }

        $this->entityManager->persist($chat);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'ok',
            'added' => array_map(static fn (User $user): int => (int) $user->getId(), $users),
        ]);
    }

    #[Route('/chats/{chatId}/members/{userId}', name: 'api_chats_members_remove', methods: ['DELETE'])]
    public function removeMember(int $chatId, int $userId, Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_group_manage')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $currentUser = $this->currentUser();
        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || 'group' !== $chat->getType()) {
            return $this->jsonError('Grupo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $currentParticipant = $this->chatParticipantRepository->findOneByChatAndUser($chatId, (int) $currentUser->getId());
        if (!$currentParticipant instanceof ChatParticipant || !$currentParticipant->isAdmin()) {
            return $this->jsonError('Solo un admin puede quitar miembros.', Response::HTTP_FORBIDDEN);
        }

        if ($userId === $currentUser->getId()) {
            return $this->jsonError('Usa la opción de salir del grupo para tu usuario.', Response::HTTP_BAD_REQUEST);
        }

        $target = $this->chatParticipantRepository->findOneByChatAndUser($chatId, $userId);
        if (!$target instanceof ChatParticipant) {
            return $this->jsonError('El miembro no pertenece al grupo.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($target);
        $this->entityManager->flush();
        $chat = $this->chatRepository->find($chatId);
        if ($chat instanceof Chat) {
            $this->syncGroupAdminsAfterChange($chat);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/chats/{chatId}/leave', name: 'api_chats_leave', methods: ['POST'])]
    public function leaveGroup(int $chatId, Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_leave')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $currentUser = $this->currentUser();
        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || 'group' !== $chat->getType()) {
            return $this->jsonError('Grupo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $participant = $this->chatParticipantRepository->findOneByChatAndUser($chatId, (int) $currentUser->getId());
        if (!$participant instanceof ChatParticipant) {
            return $this->jsonError('No perteneces al grupo.', Response::HTTP_FORBIDDEN);
        }

        $members = $this->chatParticipantRepository->findByChatOrdered($chatId);
        if (count($members) <= 1) {
            $this->entityManager->remove($chat);
            $this->entityManager->flush();

            return new JsonResponse(['status' => 'ok', 'deleted' => true]);
        }

        $this->entityManager->remove($participant);
        $this->entityManager->flush();
        $chat = $this->chatRepository->find($chatId);
        if ($chat instanceof Chat) {
            $this->syncGroupAdminsAfterChange($chat);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'ok', 'deleted' => false]);
    }

    #[Route('/chats/{chatId}/messages', name: 'api_chats_messages', methods: ['GET'])]
    public function messages(int $chatId, Request $request): JsonResponse
    {
        $currentUser = $this->currentUser();

        if (!$this->chatParticipantRepository->isUserInChat($chatId, (int) $currentUser->getId())) {
            return $this->jsonError('No tienes acceso a este chat.', Response::HTTP_FORBIDDEN);
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat) {
            return $this->jsonError('Chat no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $afterId = max(0, (int) $request->query->get('afterId', 0));
        $messages = $this->messageRepository->findByChatAfterId($chat, $afterId, 120);
        $payload = [];
        foreach ($messages as $message) {
            try {
                $payload[] = $this->chatService->serializeMessage($message, $this->encryptionService);
            } catch (\Throwable) {
                // Evita tumbar el polling por un registro histórico corrupto.
                continue;
            }
        }

        return new JsonResponse(['status' => 'ok', 'messages' => $payload]);
    }

    #[Route('/chats/{chatId}/messages', name: 'api_chats_messages_send', methods: ['POST'])]
    public function sendMessage(int $chatId, Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'chat_send')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $currentUser = $this->currentUser();

        if (!$this->chatParticipantRepository->isUserInChat($chatId, (int) $currentUser->getId())) {
            return $this->jsonError('No tienes acceso a este chat.', Response::HTTP_FORBIDDEN);
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat) {
            return $this->jsonError('Chat no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $text = trim((string) ($this->input($request, 'text') ?? ''));
        $files = $this->extractFiles($request);

        if ('' === $text && count($files) === 0) {
            return $this->jsonError('Debes enviar texto o un adjunto.', Response::HTTP_BAD_REQUEST);
        }

        $message = (new Message())
            ->setChat($chat)
            ->setSender($currentUser)
            ->setKeyVersion($this->encryptionService->getKeyVersion());

        if ('' !== $text) {
            $encrypted = $this->encryptionService->encrypt($text);
            $message
                ->setCiphertext($encrypted['ciphertext'])
                ->setNonce($encrypted['nonce'])
                ->setKeyVersion($encrypted['key_version']);
        }

        foreach ($files as $file) {
            try {
                $this->uploadPolicy->validate($file);

                $content = file_get_contents($file->getPathname());
                if (false === $content) {
                    return $this->jsonError('No se pudo leer el archivo subido.', Response::HTTP_BAD_REQUEST);
                }

                $encryptedBlob = $this->encryptionService->encrypt($content);
                $attachment = (new Attachment())
                    ->setCipherBlob($encryptedBlob['ciphertext'])
                    ->setNonce($encryptedBlob['nonce'])
                    ->setKeyVersion($encryptedBlob['key_version'])
                    ->setMimeCiphertext($this->encryptionService->encryptCombined((string) ($file->getMimeType() ?: 'application/octet-stream')))
                    ->setFilenameCiphertext($this->encryptionService->encryptCombined((string) $file->getClientOriginalName()))
                    ->setSizeBytes((int) $file->getSize());

                $message->addAttachment($attachment);
            } catch (\RuntimeException $exception) {
                return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
            }
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $response = [
            'status' => 'ok',
            'message' => $this->chatService->serializeMessage($message, $this->encryptionService),
        ];

        $botUser = $this->resolveBotParticipant($chat);
        $isAiChat = $botUser instanceof User && $currentUser->getId() !== $botUser->getId() && 'direct' === $chat->getType();

        if ($isAiChat && '' !== $text) {
            $limiterKey = sprintf('ai:%d:%s', (int) $currentUser->getId(), (string) ($request->getClientIp() ?? 'unknown'));
            if (!$this->aiLimiter->create($limiterKey)->consume(1)->isAccepted()) {
                $response['aiError'] = 'Límite temporal de mensajes para IA alcanzado. Intenta en un momento.';
            } else {
                $history = [];
                try {
                    $history = $this->buildAiHistory($chat, $botUser);
                    $aiReply = trim($this->aiChatService->generateReply($history));
                    if ('' !== $aiReply) {
                        $encryptedAi = $this->encryptionService->encrypt($aiReply);
                        $aiMessage = (new Message())
                            ->setChat($chat)
                            ->setSender($botUser)
                            ->setCiphertext($encryptedAi['ciphertext'])
                            ->setNonce($encryptedAi['nonce'])
                            ->setKeyVersion($encryptedAi['key_version']);

                        $this->entityManager->persist($aiMessage);
                        $this->entityManager->flush();

                        $response['aiMessage'] = $this->chatService->serializeMessage($aiMessage, $this->encryptionService);
                    }
                } catch (\Throwable $exception) {
                    $raw = trim($exception->getMessage());
                    $safe = preg_replace('/\\s+/', ' ', $raw ?? '');
                    $safe = mb_substr((string) $safe, 0, 220);
                    $response['aiError'] = '' !== $safe
                        ? 'OpenAI error: '.$safe
                        : 'Nubecita IA no pudo responder con OpenAI. Revisa cuota, clave o conectividad.';
                }
            }
        }

        return new JsonResponse($response, Response::HTTP_CREATED);
    }

    #[Route('/attachments/{attachmentId}', name: 'api_attachments_download', methods: ['GET'])]
    public function downloadAttachment(int $attachmentId, Request $request): Response
    {
        $currentUser = $this->currentUser();
        $attachment = $this->attachmentRepository->find($attachmentId);

        if (!$attachment instanceof Attachment) {
            return $this->jsonError('Adjunto no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $chatId = $attachment->getMessage()->getChat()->getId();
        if (null === $chatId || !$this->chatParticipantRepository->isUserInChat($chatId, (int) $currentUser->getId())) {
            return $this->jsonError('No tienes acceso a este adjunto.', Response::HTTP_FORBIDDEN);
        }

        $content = $this->encryptionService->decrypt($attachment->getCipherBlob(), $attachment->getNonce());
        $filename = $this->encryptionService->decryptCombined($attachment->getFilenameCiphertext());
        $mime = $this->encryptionService->decryptCombined($attachment->getMimeCiphertext());

        $disposition = strtolower((string) $request->query->get('disposition', 'attachment'));
        $disposition = 'inline' === $disposition ? 'inline' : 'attachment';

        $response = new Response($content);
        $response->headers->set('Content-Type', $mime ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', sprintf('%s; filename="%s"', $disposition, addslashes($filename)));

        return $response;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$user instanceof User) {
            throw $this->createAccessDeniedException('Sesión inválida.');
        }

        return $user;
    }

    private function resolvePeer(Chat $chat, User $currentUser): ?User
    {
        foreach ($chat->getParticipants() as $participant) {
            if ($participant->getUser()->getId() !== $currentUser->getId()) {
                return $participant->getUser();
            }
        }

        return null;
    }

    private function resolveBotParticipant(Chat $chat): ?User
    {
        foreach ($chat->getParticipants() as $participant) {
            if ($participant->getUser()->isBot()) {
                return $participant->getUser();
            }
        }

        return null;
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private function buildAiHistory(Chat $chat, User $botUser): array
    {
        $history = [];
        foreach ($this->messageRepository->findLatestByChat($chat, 20) as $message) {
            $text = '';
            try {
                if (null !== $message->getCiphertext() && null !== $message->getNonce()) {
                    $text = $this->encryptionService->decrypt($message->getCiphertext(), $message->getNonce());
                } elseif ($message->getAttachments()->count() > 0) {
                    $text = '[Mensaje con adjuntos]';
                }
            } catch (\Throwable) {
                // Ignora mensajes históricos corruptos/incompatibles y continúa con el contexto utilizable.
                continue;
            }

            if ('' === trim($text)) {
                continue;
            }

            $history[] = [
                'role' => (int) $message->getSender()->getId() === (int) $botUser->getId() ? 'assistant' : 'user',
                'content' => $text,
            ];
        }

        return $history;
    }

    private function syncGroupAdminsAfterChange(Chat $chat): void
    {
        if (null === $chat->getId() || 'group' !== $chat->getType()) {
            return;
        }

        $members = $this->chatParticipantRepository->findByChatOrdered((int) $chat->getId());
        if ([] === $members) {
            return;
        }

        $admins = array_values(array_filter($members, static fn (ChatParticipant $participant): bool => $participant->isAdmin()));
        if ([] === $admins) {
            $members[0]->setRole('admin');
            $chat->setOwner($members[0]->getUser());

            return;
        }

        $ownerId = $chat->getOwner()?->getId();
        if (null !== $ownerId) {
            foreach ($members as $member) {
                if ($member->getUser()->getId() === $ownerId) {
                    return;
                }
            }
        }

        $chat->setOwner($admins[0]->getUser());
    }

    private function serializeDirectoryUser(User $user): array
    {
        return [
            'id' => (int) $user->getId(),
            'displayName' => $user->getDisplayName(),
            'email' => $user->isBot() ? null : $this->encryptionService->decryptCombined($user->getEmailCiphertext()),
            'status' => $this->chatService->decryptUserStatus($user, $this->encryptionService),
            'isBot' => $user->isBot(),
            'avatarUrl' => $user->hasAvatar() ? '/api/profile/avatar/'.(int) $user->getId() : null,
        ];
    }

    private function assertCsrfToken(Request $request, string $tokenId): bool
    {
        if ('test' === $this->getParameter('kernel.environment')) {
            return true;
        }

        $token = $request->headers->get('X-CSRF-Token') ?? $this->input($request, '_csrf_token');
        if (!is_string($token) || '' === trim($token)) {
            return false;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token));
    }

    private function input(Request $request, string $key): ?string
    {
        $value = $request->request->get($key);
        if (is_string($value)) {
            return trim($value);
        }

        $json = $request->attributes->get('_json_payload');
        if (!is_array($json)) {
            $content = trim((string) $request->getContent());
            if ('' === $content) {
                $json = [];
            } else {
                try {
                    $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                    $json = is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $json = [];
                }
            }
            $request->attributes->set('_json_payload', $json);
        }

        $jsonValue = $json[$key] ?? null;

        return is_scalar($jsonValue) ? trim((string) $jsonValue) : null;
    }

    /**
     * @return list<int|string>
     */
    private function inputArray(Request $request, string $key): array
    {
        if ($request->request->has($key)) {
            $formValue = $request->request->all($key);
            if (is_array($formValue)) {
                return $formValue;
            }

            $singleValue = $request->request->get($key);
            if (is_scalar($singleValue)) {
                return [(string) $singleValue];
            }
        }

        $json = $request->attributes->get('_json_payload');
        if (!is_array($json)) {
            $this->input($request, '_noop');
            $json = $request->attributes->get('_json_payload');
        }

        if (is_array($json) && is_array($json[$key] ?? null)) {
            return $json[$key];
        }

        return [];
    }

    /**
     * @return list<UploadedFile>
     */
    private function extractFiles(Request $request): array
    {
        $files = $request->files->all('files');

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $result[] = $file;
            }
        }

        return $result;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}
