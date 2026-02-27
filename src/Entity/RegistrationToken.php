<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RegistrationTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegistrationTokenRepository::class)]
#[ORM\Table(name: 'registration_tokens')]
#[ORM\Index(name: 'idx_registration_email_hash', columns: ['email_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_registration_token_hash', columns: ['token_hash'])]
class RegistrationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $emailHash = '';

    #[ORM\Column(type: Types::BLOB)]
    private mixed $emailCiphertext;

    #[ORM\Column(length: 64)]
    private string $tokenHash = '';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

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

    public function getEmailHash(): string
    {
        return $this->emailHash;
    }

    public function setEmailHash(string $emailHash): self
    {
        $this->emailHash = strtolower($emailHash);

        return $this;
    }

    public function getEmailCiphertext(): string
    {
        if (is_resource($this->emailCiphertext)) {
            $contents = stream_get_contents($this->emailCiphertext);

            return $contents === false ? '' : $contents;
        }

        return (string) $this->emailCiphertext;
    }

    public function setEmailCiphertext(string $emailCiphertext): self
    {
        $this->emailCiphertext = $emailCiphertext;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = strtolower($tokenHash);

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): self
    {
        $this->usedAt = $usedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $this->expiresAt > $now;
    }
}
