<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findUsableByHash(string $tokenHash, \DateTimeImmutable $now): ?PasswordResetToken
    {
        return $this->createQueryBuilder('prt')
            ->andWhere('prt.tokenHash = :hash')
            ->andWhere('prt.usedAt IS NULL')
            ->andWhere('prt.expiresAt > :now')
            ->setParameter('hash', strtolower($tokenHash))
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function cleanupExpired(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('prt')
            ->delete()
            ->where('prt.expiresAt < :threshold OR prt.usedAt IS NOT NULL')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
