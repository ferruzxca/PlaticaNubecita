<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AvatarUploadPolicy;
use App\Service\ChatService;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_USER')]
#[Route('/api/profile')]
class ApiProfileController extends AbstractController
{
    private const AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly ChatService $chatService,
        private readonly AvatarUploadPolicy $avatarUploadPolicy,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ValidatorInterface $validator,
        #[Autowire('%env(int:APP_MAX_AVATAR_BYTES)%')]
        private readonly int $maxAvatarBytes,
    ) {
    }

    #[Route('', name: 'api_profile_get', methods: ['GET'])]
    public function getProfile(): JsonResponse
    {
        $user = $this->currentUser();
        $avatarFile = $this->findAvatarFile((int) $user->getId());
        $avatarUrl = $avatarFile ? $avatarFile['url'] : null;

        return new JsonResponse([
            'status' => 'ok',
            'profile' => [
                'id' => (int) $user->getId(),
                'displayName' => $user->getDisplayName(),
                'statusText' => $this->chatService->decryptUserStatus($user, $this->encryptionService),
                'hasAvatar' => null !== $avatarUrl,
                'avatarUrl' => $avatarUrl,
            ],
        ]);
    }

    #[Route('', name: 'api_profile_update', methods: ['POST'])]
    public function updateProfile(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'profile_update')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->currentUser();
        $displayName = trim((string) ($request->request->get('displayName') ?? ''));
        $statusText = trim((string) ($request->request->get('statusText') ?? ''));
        $removeAvatar = '1' === (string) $request->request->get('removeAvatar', '0');

        $violations = $this->validator->validate($displayName, [new Assert\NotBlank(), new Assert\Length(min: 3, max: 80)]);
        $violations->addAll($this->validator->validate($statusText, [new Assert\Length(max: 140)]));
        if (count($violations) > 0) {
            return $this->jsonError('Datos de perfil inválidos.', Response::HTTP_BAD_REQUEST);
        }

        $user->setDisplayName($displayName);
        $user->setStatusCiphertext('' === $statusText ? null : $this->encryptionService->encryptCombined($statusText));

        $avatar = $request->files->get('avatar');
        $avatarBinary = null;
        $avatarMime = null;
        if ($avatar instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            try {
                $this->avatarUploadPolicy->validate($avatar);
                $avatarBinary = file_get_contents($avatar->getPathname());
                if (false === $avatarBinary || '' === $avatarBinary) {
                    return $this->jsonError('No se pudo leer el avatar.', Response::HTTP_BAD_REQUEST);
                }

                $avatarMime = (string) ($avatar->getMimeType() ?: 'application/octet-stream');
            } catch (\RuntimeException $exception) {
                return $this->jsonError($exception->getMessage(), Response::HTTP_BAD_REQUEST);
            }
        } elseif ($avatarData = (string) $request->request->get('avatarData', '')) {
            $avatarData = trim($avatarData);
            if (!preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#i', $avatarData, $matches)) {
                return $this->jsonError('Formato de avatar inválido.', Response::HTTP_BAD_REQUEST);
            }

            $mime = strtolower($matches[1]);
            if (!str_starts_with($mime, 'image/')) {
                return $this->jsonError('Formato de avatar inválido.', Response::HTTP_BAD_REQUEST);
            }
            if ('image/svg+xml' === $mime) {
                return $this->jsonError('Formato SVG no permitido.', Response::HTTP_BAD_REQUEST);
            }

            $avatarBinary = base64_decode($matches[2], true);
            if (false === $avatarBinary || '' === $avatarBinary) {
                return $this->jsonError('No se pudo leer el avatar.', Response::HTTP_BAD_REQUEST);
            }

            if (strlen($avatarBinary) > $this->maxAvatarBytes) {
                return $this->jsonError(sprintf('La imagen excede el límite permitido de %d bytes.', $this->maxAvatarBytes), Response::HTTP_BAD_REQUEST);
            }

            $avatarMime = $mime;
        } elseif ($removeAvatar) {
            $user
                ->setAvatarBlob(null)
                ->setAvatarNonce(null)
                ->setAvatarMimeCiphertext(null)
                ->setAvatarKeyVersion($this->encryptionService->getKeyVersion());
        }

        if (null !== $avatarBinary && null !== $avatarMime) {
            $this->storeAvatarFile((int) $user->getId(), $avatarBinary, $avatarMime);
            $user
                ->setAvatarBlob(null)
                ->setAvatarNonce(null)
                ->setAvatarMimeCiphertext(null)
                ->setAvatarKeyVersion($this->encryptionService->getKeyVersion());
        } elseif ($removeAvatar) {
            $this->removeAvatarFiles((int) $user->getId());
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $avatarFile = $this->findAvatarFile((int) $user->getId());
        $avatarUrl = $avatarFile ? $avatarFile['url'] : null;

        return new JsonResponse([
            'status' => 'ok',
            'profile' => [
                'id' => (int) $user->getId(),
                'displayName' => $user->getDisplayName(),
                'statusText' => $this->chatService->decryptUserStatus($user, $this->encryptionService),
                'hasAvatar' => null !== $avatarUrl,
                'avatarUrl' => $avatarUrl,
            ],
        ]);
    }

    #[Route('/avatar/{userId}', name: 'api_profile_avatar', methods: ['GET'])]
    public function avatar(int $userId): Response
    {
        $viewer = $this->currentUser();
        if (!$viewer->isActive()) {
            return $this->jsonError('Usuario no autorizado.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->find($userId);
        if (!$user instanceof User || !$user->isActive()) {
            return $this->jsonError('Avatar no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $file = $this->findAvatarFile((int) $user->getId());
        if (null === $file) {
            return $this->jsonError('Avatar no disponible.', Response::HTTP_NOT_FOUND);
        }

        $content = file_get_contents($file['path']);
        if (false === $content) {
            return $this->jsonError('Avatar no disponible.', Response::HTTP_NOT_FOUND);
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', $file['mime']);
        $response->headers->set('Content-Disposition', 'inline');
        $response->headers->set('Cache-Control', 'private, max-age=120');

        return $response;
    }

    private function avatarStorageDir(): string
    {
        $dir = rtrim($this->getParameter('kernel.project_dir'), '/').'/public/uploads/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * @return array{path:string,mime:string,url:string}|null
     */
    private function findAvatarFile(int $userId): ?array
    {
        $dir = $this->avatarStorageDir();
        foreach (self::AVATAR_EXTENSIONS as $ext) {
            $path = $dir.'/'.$userId.'.'.$ext;
            if (is_file($path)) {
                return [
                    'path' => $path,
                    'mime' => $this->extensionToMime($ext),
                    'url' => '/uploads/avatars/'.$userId.'.'.$ext.'?v='.time(),
                ];
            }
        }

        return null;
    }

    private function removeAvatarFiles(int $userId): void
    {
        $dir = $this->avatarStorageDir();
        foreach (self::AVATAR_EXTENSIONS as $ext) {
            $path = $dir.'/'.$userId.'.'.$ext;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function storeAvatarFile(int $userId, string $binary, string $mime): void
    {
        $extension = match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default => 'jpg',
        };

        $dir = $this->avatarStorageDir();
        $path = $dir.'/'.$userId.'.'.$extension;
        $this->removeAvatarFiles($userId);
        @file_put_contents($path, $binary);
    }

    private function extensionToMime(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            default => 'application/octet-stream',
        };
    }

    private function assertCsrfToken(Request $request, string $tokenId): bool
    {
        if ('test' === $this->getParameter('kernel.environment')) {
            return true;
        }

        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf_token');
        if (!is_string($token) || '' === trim($token)) {
            return false;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$user instanceof User) {
            throw $this->createAccessDeniedException('Sesión inválida.');
        }

        return $user;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}
