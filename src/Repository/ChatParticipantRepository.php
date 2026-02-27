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
            ->addSelect('c')
            ->join('cp.chat', 'c')
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
}
