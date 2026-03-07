<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneActiveByEmailHash(string $emailHash): ?User
    {
        return $this->findOneBy([
            'emailHash' => strtolower($emailHash),
            'isActive' => true,
        ]);
    }

    public function findOneByEmailHash(string $emailHash): ?User
    {
        return $this->findOneBy(['emailHash' => strtolower($emailHash)]);
    }

    /**
     * @return list<User>
     */
    public function findActiveUsersExcept(int $userId): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.id != :current')
            ->setParameter('current', $userId)
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $ids
     * @return list<User>
     */
    public function findActiveUsersByIds(array $ids): array
    {
        $cleanIds = array_values(array_unique(array_map(static fn ($id): int => max(0, (int) $id), $ids)));
        if ([] === $cleanIds) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->andWhere('u.isActive = true')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', $cleanIds)
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
