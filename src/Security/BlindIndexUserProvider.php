<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class BlindIndexUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EncryptionService $encryptionService,
    ) {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException('Tipo de usuario inválido.');
        }

        $id = $user->getId();
        if (null === $id) {
            throw new UserNotFoundException('Usuario no persistido.');
        }

        $freshUser = $this->userRepository->find($id);
        if (!$freshUser instanceof User || !$freshUser->isActive()) {
            $exception = new UserNotFoundException('Usuario no encontrado o inactivo.');
            $exception->setUserIdentifier($user->getUserIdentifier());
            throw $exception;
        }

        return $freshUser;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $identifier = trim($identifier);
        $hash = preg_match('/^[a-f0-9]{64}$/', $identifier)
            ? strtolower($identifier)
            : $this->encryptionService->emailBlindIndex($identifier);

        $user = $this->userRepository->findOneActiveByEmailHash($hash);
        if (!$user instanceof User) {
            $exception = new UserNotFoundException('Credenciales inválidas.');
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        return $user;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException('Tipo de usuario inválido.');
        }

        $user->setPassword($newHashedPassword);
        $entityManager = $this->userRepository->getEntityManager();
        $entityManager->persist($user);
        $entityManager->flush();
    }
}
