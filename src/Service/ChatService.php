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

        $chat->addParticipant((new ChatParticipant())->setUser($owner)->setRole('member'));
        $chat->addParticipant((new ChatParticipant())->setUser($target)->setRole('member'));

        return $chat;
    }

    /**
     * @param list<User> $members
     */
    public function createGroupChat(User $owner, array $members, string $groupName, EncryptionService $encryptionService): Chat
    {
        $name = trim($groupName);
        if ('' === $name) {
            throw new \InvalidArgumentException('El nombre del grupo es obligatorio.');
        }

        $encryptedName = $encryptionService->encrypt($name);

        $chat = (new Chat())
            ->setType('group')
            ->setPairHash(null)
            ->setOwner($owner)
            ->setNameCiphertext($encryptedName['ciphertext'])
            ->setNameNonce($encryptedName['nonce']);

        $chat->addParticipant((new ChatParticipant())->setUser($owner)->setRole('admin'));
        foreach ($members as $member) {
            if ($member->getId() === $owner->getId()) {
                continue;
            }

            $chat->addParticipant((new ChatParticipant())->setUser($member)->setRole('member'));
        }

        return $chat;
    }

    public function decryptUserStatus(User $user, EncryptionService $encryptionService): ?string
    {
        $cipher = $user->getStatusCiphertext();
        if (null === $cipher || '' === $cipher) {
            return null;
        }

        return $encryptionService->decryptCombined($cipher);
    }

    public function decryptGroupName(Chat $chat, EncryptionService $encryptionService): ?string
    {
        $cipher = $chat->getNameCiphertext();
        $nonce = $chat->getNameNonce();
        if (null === $cipher || null === $nonce) {
            return null;
        }

        return $encryptionService->decrypt($cipher, $nonce);
    }

    /**
     * @return array{id:int,senderId:int,senderName:string,text:?string,createdAt:string,attachments:list<array{id:int,filename:string,mime:string,sizeBytes:int,previewType:string,inlineUrl:string,downloadUrl:string}>}
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
                'mime' => $mime = $encryptionService->decryptCombined($attachment->getMimeCiphertext()),
                'sizeBytes' => $attachment->getSizeBytes(),
                'previewType' => $this->resolvePreviewType($mime),
                'inlineUrl' => '/api/attachments/'.(int) $attachment->getId().'?disposition=inline',
                'downloadUrl' => '/api/attachments/'.(int) $attachment->getId().'?disposition=attachment',
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

    public function resolvePreviewType(string $mime): string
    {
        $value = strtolower(trim($mime));
        if (str_starts_with($value, 'image/')) {
            return 'image';
        }

        if (str_starts_with($value, 'audio/')) {
            return 'audio';
        }

        if ('video/mp4' === $value || str_starts_with($value, 'video/')) {
            return 'video';
        }

        return 'file';
    }
}
