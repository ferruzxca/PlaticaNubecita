<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class BotUserService
{
    public const BOT_DISPLAY_NAME = 'Nubecita IA';
    public const BOT_EMAIL = 'nubecita.ai@ferruzca.pro';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EncryptionService $encryptionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function ensureBotUser(): User
    {
        $normalizedEmail = $this->encryptionService->normalizeEmail(self::BOT_EMAIL);
        $emailHash = $this->encryptionService->emailBlindIndex($normalizedEmail);

        $bot = $this->userRepository->findOneByEmailHash($emailHash);
        if ($bot instanceof User) {
            $changed = false;
            if (!$bot->isBot()) {
                $bot->setIsBot(true);
                $changed = true;
            }
            if (!$bot->isActive()) {
                $bot->setIsActive(true);
                $changed = true;
            }
            if (self::BOT_DISPLAY_NAME !== $bot->getDisplayName()) {
                $bot->setDisplayName(self::BOT_DISPLAY_NAME);
                $changed = true;
            }

            if ($changed) {
                $this->entityManager->persist($bot);
                $this->entityManager->flush();
            }

            return $bot;
        }

        $bot = (new User())
            ->setDisplayName(self::BOT_DISPLAY_NAME)
            ->setEmailHash($emailHash)
            ->setEmailCiphertext($this->encryptionService->encryptCombined($normalizedEmail))
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true)
            ->setIsBot(true)
            ->setPassword((string) password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT));

        $this->entityManager->persist($bot);
        $this->entityManager->flush();

        return $bot;
    }
}
