<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachments')]
class Attachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(type: Types::BLOB)]
    private mixed $cipherBlob;

    #[ORM\Column(type: Types::BINARY, length: 24)]
    private string $nonce = '';

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion = 1;

    #[ORM\Column(type: Types::BLOB)]
    private mixed $mimeCiphertext;

    #[ORM\Column(type: Types::BLOB)]
    private mixed $filenameCiphertext;

    #[ORM\Column]
    private int $sizeBytes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function setMessage(Message $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getCipherBlob(): string
    {
        if (is_resource($this->cipherBlob)) {
            $contents = stream_get_contents($this->cipherBlob);

            return $contents === false ? '' : $contents;
        }

        return (string) $this->cipherBlob;
    }

    public function setCipherBlob(string $cipherBlob): self
    {
        $this->cipherBlob = $cipherBlob;

        return $this;
    }

    public function getNonce(): string
    {
        return $this->nonce;
    }

    public function setNonce(string $nonce): self
    {
        $this->nonce = $nonce;

        return $this;
    }

    public function getKeyVersion(): int
    {
        return $this->keyVersion;
    }

    public function setKeyVersion(int $keyVersion): self
    {
        $this->keyVersion = $keyVersion;

        return $this;
    }

    public function getMimeCiphertext(): string
    {
        if (is_resource($this->mimeCiphertext)) {
            $contents = stream_get_contents($this->mimeCiphertext);

            return $contents === false ? '' : $contents;
        }

        return (string) $this->mimeCiphertext;
    }

    public function setMimeCiphertext(string $mimeCiphertext): self
    {
        $this->mimeCiphertext = $mimeCiphertext;

        return $this;
    }

    public function getFilenameCiphertext(): string
    {
        if (is_resource($this->filenameCiphertext)) {
            $contents = stream_get_contents($this->filenameCiphertext);

            return $contents === false ? '' : $contents;
        }

        return (string) $this->filenameCiphertext;
    }

    public function setFilenameCiphertext(string $filenameCiphertext): self
    {
        $this->filenameCiphertext = $filenameCiphertext;

        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): self
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
