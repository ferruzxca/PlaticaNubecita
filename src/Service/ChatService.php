<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Chat;
use App\Entity\ChatParticipant;
use App\Entity\Message;
use App\Entity\User;

class ChatService
{
    public function buildPairHash(User $userA, User $userB): string
    {
        $a = $userA->getId();
        $b = $userB->getId();

        if (null === $a || null === $b) {
            throw new \InvalidArgumentException('Los usuarios deben estar persistidos antes de crear el chat.');
        }

        $ids = [$a, $b];
        sort($ids, SORT_NUMERIC);

        return hash('sha256', sprintf('%d:%d', $ids[0], $ids[1]));
    }

    public function createDirectChat(User $owner, User $target): Chat
    {
        $chat = (new Chat())
            ->setType('direct')
            ->setPairHash($this->buildPairHash($owner, $target));

        $chat->addParticipant((new ChatParticipant())->setUser($owner));
        $chat->addParticipant((new ChatParticipant())->setUser($target));

        return $chat;
    }

    /**
     * @return array{id:int,senderId:int,senderName:string,text:?string,createdAt:string,attachments:list<array{id:int,filename:string,mime:string,sizeBytes:int}>}
     */
    public function serializeMessage(Message $message, EncryptionService $encryptionService): array
    {
        $text = null;
        $ciphertext = $message->getCiphertext();
        if (null !== $ciphertext && null !== $message->getNonce()) {
            $text = $encryptionService->decrypt($ciphertext, $message->getNonce());
        }

        $attachments = array_map(
            fn (Attachment $attachment): array => [
                'id' => (int) $attachment->getId(),
                'filename' => $encryptionService->decryptCombined($attachment->getFilenameCiphertext()),
                'mime' => $encryptionService->decryptCombined($attachment->getMimeCiphertext()),
                'sizeBytes' => $attachment->getSizeBytes(),
            ],
            $message->getAttachments()->toArray(),
        );

        return [
            'id' => (int) $message->getId(),
            'senderId' => (int) $message->getSender()->getId(),
            'senderName' => $message->getSender()->getDisplayName(),
            'text' => $text,
            'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
            'attachments' => $attachments,
        ];
    }
}
