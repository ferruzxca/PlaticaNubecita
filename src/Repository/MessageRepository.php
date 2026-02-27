<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Chat;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * @return list<Message>
     */
    public function findByChatAfterId(Chat $chat, int $afterId, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a', 's')
            ->leftJoin('m.attachments', 'a')
            ->join('m.sender', 's')
            ->andWhere('m.chat = :chat')
            ->andWhere('m.id > :afterId')
            ->setParameter('chat', $chat)
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLastByChat(Chat $chat): ?Message
    {
        return $this->findOneBy(['chat' => $chat], ['id' => 'DESC']);
    }
}
