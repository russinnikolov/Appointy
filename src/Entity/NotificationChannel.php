<?php

namespace App\Entity;

use App\Enum\NotificationChannelType;
use App\Repository\NotificationChannelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationChannelRepository::class)]
#[ORM\Table(name: 'notification_channel')]
#[ORM\HasLifecycleCallbacks]
class NotificationChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 20, enumType: NotificationChannelType::class)]
    private NotificationChannelType $type;

    /** Telegram chat_id (or future channel's identifier) once paired. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    /** One-time pairing token, cleared once the channel is successfully connected. */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $linkToken = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isConnected(): bool { return $this->externalId !== null; }

    public function getId(): ?int { return $this->id; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getType(): NotificationChannelType { return $this->type; }
    public function setType(NotificationChannelType $v): static { $this->type = $v; return $this; }

    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $v): static { $this->externalId = $v; return $this; }

    public function getLinkToken(): ?string { return $this->linkToken; }
    public function setLinkToken(?string $v): static { $this->linkToken = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
