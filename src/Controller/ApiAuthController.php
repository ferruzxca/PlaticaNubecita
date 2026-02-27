<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LoginAudit;
use App\Entity\PasswordResetToken;
use App\Entity\RegistrationToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\RegistrationTokenRepository;
use App\Repository\UserRepository;
use App\Service\EmailService;
use App\Service\EncryptionService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/auth')]
class ApiAuthController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RegistrationTokenRepository $registrationTokenRepository,
        private readonly PasswordResetTokenRepository $passwordResetTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly TokenService $tokenService,
        private readonly EmailService $emailService,
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'limiter.auth_public')]
        private readonly RateLimiterFactory $publicLimiter,
        #[Autowire(service: 'limiter.auth_login')]
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'auth_login')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($this->loginLimiter, $request->getClientIp() ?? 'unknown')) {
            return $this->jsonError('Demasiados intentos de inicio de sesión.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $email = (string) $this->input($request, 'email');
        $password = (string) $this->input($request, 'password');

        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        $violations->addAll($this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8)]));

        if (count($violations) > 0) {
            return $this->jsonError('Datos de acceso inválidos.', Response::HTTP_BAD_REQUEST);
        }

        $emailHash = $this->encryptionService->emailBlindIndex($email);
        $user = $this->userRepository->findOneActiveByEmailHash($emailHash);

        $audit = (new LoginAudit())
            ->setEmailHash($emailHash)
            ->setIpAddress($request->getClientIp() ?: null);

        if (!$user instanceof User || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $audit->setSuccess(false);
            $this->entityManager->persist($audit);
            $this->entityManager->flush();

            return $this->jsonError('Credenciales inválidas.', Response::HTTP_UNAUTHORIZED);
        }

        $audit->setSuccess(true);
        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        $this->security->login($user, firewallName: 'main');

        return new JsonResponse([
            'status' => 'ok',
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/request-registration-link', name: 'api_auth_request_registration_link', methods: ['POST'])]
    public function requestRegistrationLink(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'auth_public')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($this->publicLimiter, $request->getClientIp() ?? 'unknown')) {
            return $this->jsonError('Demasiadas solicitudes. Espera un momento.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $email = (string) $this->input($request, 'email');
        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);

        if (count($violations) > 0) {
            return $this->jsonError('Correo inválido.', Response::HTTP_BAD_REQUEST);
        }

        $emailHash = $this->encryptionService->emailBlindIndex($email);

        if (!$this->userRepository->findOneActiveByEmailHash($emailHash)) {
            $plainToken = $this->tokenService->generateToken();
            $registrationToken = (new RegistrationToken())
                ->setEmailHash($emailHash)
                ->setEmailCiphertext($this->encryptionService->encryptCombined($this->encryptionService->normalizeEmail($email)))
                ->setTokenHash($this->tokenService->hashToken($plainToken))
                ->setExpiresAt((new \DateTimeImmutable())->modify('+30 minutes'));

            $this->entityManager->persist($registrationToken);
            $this->entityManager->flush();

            $url = $this->urlGenerator->generate('app_register_page', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->emailService->sendTemplate(
                $this->encryptionService->normalizeEmail($email),
                'Crea tu cuenta en PlaticaNubecita',
                'email/registration_link.html.twig',
                ['url' => $url, 'expiresMinutes' => 30],
            );
        }

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'Si el correo no está registrado, te enviamos un enlace de alta.',
        ]);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'auth_register')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $token = (string) $this->input($request, 'token');
        $displayName = trim((string) $this->input($request, 'displayName'));
        $password = (string) $this->input($request, 'password');

        $violations = $this->validator->validate($token, [new Assert\NotBlank()]);
        $violations->addAll($this->validator->validate($displayName, [new Assert\NotBlank(), new Assert\Length(min: 3, max: 80)]));
        $violations->addAll($this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8, max: 72)]));

        if (count($violations) > 0) {
            return $this->jsonError('Datos de registro inválidos.', Response::HTTP_BAD_REQUEST);
        }

        $tokenHash = $this->tokenService->hashToken($token);
        $registrationToken = $this->registrationTokenRepository->findUsableByHash($tokenHash, new \DateTimeImmutable());

        if (!$registrationToken instanceof RegistrationToken) {
            return $this->jsonError('El enlace de registro no es válido o ya expiró.', Response::HTTP_BAD_REQUEST);
        }

        $existingUser = $this->userRepository->findOneActiveByEmailHash($registrationToken->getEmailHash());
        if ($existingUser instanceof User) {
            return $this->jsonError('El correo ya tiene una cuenta activa.', Response::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setDisplayName($displayName)
            ->setEmailHash($registrationToken->getEmailHash())
            ->setEmailCiphertext($registrationToken->getEmailCiphertext())
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $registrationToken->setUsedAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->persist($registrationToken);
        $this->entityManager->flush();

        $this->security->login($user, firewallName: 'main');

        return new JsonResponse([
            'status' => 'ok',
            'userId' => $user->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/forgot-password', name: 'api_auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'auth_public')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($this->publicLimiter, 'forgot-'.$request->getClientIp())) {
            return $this->jsonError('Demasiadas solicitudes. Espera un momento.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $email = (string) $this->input($request, 'email');
        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);

        if (count($violations) > 0) {
            return $this->jsonError('Correo inválido.', Response::HTTP_BAD_REQUEST);
        }

        $emailHash = $this->encryptionService->emailBlindIndex($email);
        $user = $this->userRepository->findOneActiveByEmailHash($emailHash);

        if ($user instanceof User) {
            $plainToken = $this->tokenService->generateToken();
            $resetToken = (new PasswordResetToken())
                ->setUser($user)
                ->setTokenHash($this->tokenService->hashToken($plainToken))
                ->setExpiresAt((new \DateTimeImmutable())->modify('+30 minutes'));

            $this->entityManager->persist($resetToken);
            $this->entityManager->flush();

            $url = $this->urlGenerator->generate('app_reset_password_page', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
            $emailValue = $this->encryptionService->decryptCombined($user->getEmailCiphertext());

            $this->emailService->sendTemplate(
                $emailValue,
                'Restablece tu contraseña en PlaticaNubecita',
                'email/password_reset.html.twig',
                ['url' => $url, 'expiresMinutes' => 30],
            );
        }

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'Si existe una cuenta, enviamos un enlace para restablecer la contraseña.',
        ]);
    }

    #[Route('/reset-password', name: 'api_auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'auth_reset')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $token = (string) $this->input($request, 'token');
        $password = (string) $this->input($request, 'password');

        $violations = $this->validator->validate($token, [new Assert\NotBlank()]);
        $violations->addAll($this->validator->validate($password, [new Assert\NotBlank(), new Assert\Length(min: 8, max: 72)]));

        if (count($violations) > 0) {
            return $this->jsonError('Solicitud inválida.', Response::HTTP_BAD_REQUEST);
        }

        $tokenHash = $this->tokenService->hashToken($token);
        $resetToken = $this->passwordResetTokenRepository->findUsableByHash($tokenHash, new \DateTimeImmutable());

        if (!$resetToken instanceof PasswordResetToken) {
            return $this->jsonError('El enlace para restablecer contraseña es inválido o expiró.', Response::HTTP_BAD_REQUEST);
        }

        $user = $resetToken->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $resetToken->setUsedAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);
        $this->entityManager->persist($resetToken);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        if (!$this->assertCsrfToken($request, 'auth_logout')) {
            return $this->jsonError('Token CSRF inválido.', Response::HTTP_FORBIDDEN);
        }

        $this->tokenStorage->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new JsonResponse(['status' => 'ok']);
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

    private function consumeLimiter(RateLimiterFactory $factory, string $key): bool
    {
        return $factory->create($key)->consume(1)->isAccepted();
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

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }

    /**
     * @return array{id:int,displayName:string}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => (int) $user->getId(),
            'displayName' => $user->getDisplayName(),
        ];
    }
}
