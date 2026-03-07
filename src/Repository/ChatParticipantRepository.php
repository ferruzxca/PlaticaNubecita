<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatParticipant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatParticipant>
 */
class ChatParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatParticipant::class);
    }

    /**
     * @return list<ChatParticipant>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('cp')
            ->addSelect('c', 'u')
            ->join('cp.chat', 'c')
            ->join('cp.user', 'u')
            ->andWhere('cp.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function isUserInChat(int $chatId, int $userId): bool
    {
        $count = $this->createQueryBuilder('cp')
            ->select('COUNT(cp.id)')
            ->andWhere('cp.chat = :chatId')
            ->andWhere('cp.user = :userId')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function findOneByChatAndUser(int $chatId, int $userId): ?ChatParticipant
    {
        return $this->createQueryBuilder('cp')
            ->addSelect('u', 'c')
            ->join('cp.user', 'u')
            ->join('cp.chat', 'c')
            ->andWhere('cp.chat = :chatId')
            ->andWhere('cp.user = :userId')
            ->setParameter('chatId', $chatId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ChatParticipant>
     */
    public function findByChatOrdered(int $chatId): array
    {
        return $this->createQueryBuilder('cp')
            ->addSelect('u')
            ->join('cp.user', 'u')
            ->andWhere('cp.chat = :chatId')
            ->setParameter('chatId', $chatId)
            ->orderBy('cp.joinedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
