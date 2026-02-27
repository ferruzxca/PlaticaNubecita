<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PasswordResetTokenRepository;
use App\Repository\RegistrationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:tokens:cleanup', description: 'Elimina tokens expirados o utilizados.')]
class CleanupTokensCommand extends Command
{
    public function __construct(
        private readonly RegistrationTokenRepository $registrationTokenRepository,
        private readonly PasswordResetTokenRepository $passwordResetTokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $threshold = (new \DateTimeImmutable())->modify('-1 minute');

        $registrationDeleted = $this->registrationTokenRepository->cleanupExpired($threshold);
        $passwordDeleted = $this->passwordResetTokenRepository->cleanupExpired($threshold);

        $this->entityManager->flush();

        $io->success(sprintf('Tokens limpiados. Registro: %d, Reset: %d', $registrationDeleted, $passwordDeleted));

        return Command::SUCCESS;
    }
}
