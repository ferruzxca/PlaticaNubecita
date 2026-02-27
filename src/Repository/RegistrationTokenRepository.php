<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RegistrationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistrationToken>
 */
class RegistrationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistrationToken::class);
    }

    public function findUsableByHash(string $tokenHash, \DateTimeImmutable $now): ?RegistrationToken
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.tokenHash = :hash')
            ->andWhere('rt.usedAt IS NULL')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('hash', strtolower($tokenHash))
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function cleanupExpired(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.expiresAt < :threshold OR rt.usedAt IS NOT NULL')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
