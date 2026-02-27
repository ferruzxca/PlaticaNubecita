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
}
