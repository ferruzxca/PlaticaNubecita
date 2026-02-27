<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Attachment;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\ChatParticipantRepository;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\ChatService;
use App\Service\EncryptionService;
use App\Service\UploadPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    ) {
    }

    #[Route('/users', name: 'api_users', methods: ['GET'])]
    public function users(): JsonResponse
    {
        $currentUser = $this->currentUser();
        $users = $this->userRepository->findActiveUsersExcept((int) $currentUser->getId());

        $payload = array_map(
            fn (User $user): array => [
                'id' => (int) $user->getId(),
                'displayName' => $user->getDisplayName(),
                'email' => $this->encryptionService->decryptCombined($user->getEmailCiphertext()),
            ],
            $users,
        );

        return new JsonResponse(['status' => 'ok', 'users' => $payload]);
    }

    #[Route('/chats', name: 'api_chats', methods: ['GET'])]
    public function chats(): JsonResponse
    {
        $currentUser = $this->currentUser();
        $participants = $this->chatParticipantRepository->findByUser($currentUser);

        $chats = [];
        foreach ($participants as $participant) {
            $chat = $participant->getChat();
            $peer = $this->resolvePeer($chat, $currentUser);
            if (!$peer instanceof User) {
                continue;
            }

            $lastMessage = $this->messageRepository->findLastByChat($chat);
            $lastText = null;
            $lastAt = null;
            if ($lastMessage instanceof Message) {
                $cipher = $lastMessage->getCiphertext();
                if (null !== $cipher && null !== $lastMessage->getNonce()) {
                    $lastText = $this->encryptionService->decrypt($cipher, $lastMessage->getNonce());
                } elseif ($lastMessage->getAttachments()->count() > 0) {
                    $lastText = '[Adjunto]';
                }
                $lastAt = $lastMessage->getCreatedAt()->format(DATE_ATOM);
            }

            $chats[] = [
                'chatId' => (int) $chat->getId(),
                'peer' => [
                    'id' => (int) $peer->getId(),
                    'displayName' => $peer->getDisplayName(),
                    'email' => $this->encryptionService->decryptCombined($peer->getEmailCiphertext()),
                ],
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
        $payload = array_map(fn (Message $message): array => $this->chatService->serializeMessage($message, $this->encryptionService), $messages);

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
        }

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'ok',
            'message' => $this->chatService->serializeMessage($message, $this->encryptionService),
        ], Response::HTTP_CREATED);
    }

    #[Route('/attachments/{attachmentId}', name: 'api_attachments_download', methods: ['GET'])]
    public function downloadAttachment(int $attachmentId): Response
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

        $response = new Response($content);
        $response->headers->set('Content-Type', $mime ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', addslashes($filename)));

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
