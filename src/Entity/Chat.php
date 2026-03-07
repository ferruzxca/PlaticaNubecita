<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatRepository::class)]
#[ORM\Table(name: 'chats')]
#[ORM\UniqueConstraint(name: 'uniq_chat_pair_hash', columns: ['pair_hash'])]
class Chat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $type = 'direct';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $pairHash = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private mixed $nameCiphertext = null;

    #[ORM\Column(type: Types::BINARY, length: 24, nullable: true)]
    private ?string $nameNonce = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, ChatParticipant>
     */
    #[ORM\OneToMany(mappedBy: 'chat', targetEntity: ChatParticipant::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $participants;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(mappedBy: 'chat', targetEntity: Message::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $messages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPairHash(): ?string
    {
        return $this->pairHash;
    }

    public function setPairHash(?string $pairHash): self
    {
        $this->pairHash = null === $pairHash ? null : strtolower($pairHash);

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getNameCiphertext(): ?string
    {
        if (null === $this->nameCiphertext) {
            return null;
        }

        if (is_resource($this->nameCiphertext)) {
            $contents = stream_get_contents($this->nameCiphertext);

            return $contents === false ? null : $contents;
        }

        return (string) $this->nameCiphertext;
    }

    public function setNameCiphertext(?string $nameCiphertext): self
    {
        $this->nameCiphertext = $nameCiphertext;

        return $this;
    }

    public function getNameNonce(): ?string
    {
        return $this->nameNonce;
    }

    public function setNameNonce(?string $nameNonce): self
    {
        $this->nameNonce = $nameNonce;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, ChatParticipant>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(ChatParticipant $participant): self
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            $participant->setChat($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }
}
