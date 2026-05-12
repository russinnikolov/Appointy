<?php

namespace App\Entity;

use App\Repository\AppointmentRepository;
use App\Entity\Service;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppointmentRepository::class)]
#[ORM\Table(name: 'appointment')]
#[ORM\HasLifecycleCallbacks]
class Appointment
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null for guest bookings */
    #[ORM\ManyToOne(inversedBy: 'appointments')]
    #[ORM\JoinColumn(nullable: true, name: 'client_id', onDelete: 'SET NULL')]
    private ?User $client = null;

    #[ORM\ManyToOne(inversedBy: 'appointments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(inversedBy: 'appointments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Employee $employee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Service $service = null;

    /** Guest name when booked without an account */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $guestName = null;

    /** Guest phone when booked without an account */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $guestPhone = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $appointmentDate = null;

    #[ORM\Column(type: 'time')]
    private ?\DateTimeInterface $appointmentTime = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cancellationNote = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

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
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClient(): ?User { return $this->client; }
    public function setClient(?User $v): static { $this->client = $v; return $this; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getEmployee(): ?Employee { return $this->employee; }
    public function setEmployee(?Employee $v): static { $this->employee = $v; return $this; }

    public function getService(): ?Service { return $this->service; }
    public function setService(?Service $v): static { $this->service = $v; return $this; }

    public function getGuestName(): ?string { return $this->guestName; }
    public function setGuestName(?string $v): static { $this->guestName = $v; return $this; }

    public function getGuestPhone(): ?string { return $this->guestPhone; }
    public function setGuestPhone(?string $v): static { $this->guestPhone = $v; return $this; }

    /** Returns the display name regardless of whether booked as guest or account */
    public function getBookerName(): string
    {
        return $this->client?->getName() ?? $this->guestName ?? 'Unknown';
    }

    /** Returns the contact phone regardless of booking type */
    public function getBookerPhone(): ?string
    {
        return $this->client?->getPhone() ?? $this->guestPhone;
    }

    public function isGuestBooking(): bool { return $this->client === null; }

    public function getAppointmentDate(): ?\DateTimeInterface { return $this->appointmentDate; }
    public function setAppointmentDate(\DateTimeInterface $v): static { $this->appointmentDate = $v; return $this; }

    public function getAppointmentTime(): ?\DateTimeInterface { return $this->appointmentTime; }
    public function setAppointmentTime(\DateTimeInterface $v): static { $this->appointmentTime = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v; return $this; }

    public function getCancellationNote(): ?string { return $this->cancellationNote; }
    public function setCancellationNote(?string $v): static { $this->cancellationNote = $v; return $this; }

    public function getDurationMinutes(): ?int { return $this->durationMinutes; }
    public function setDurationMinutes(?int $v): static { $this->durationMinutes = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
