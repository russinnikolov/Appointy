<?php

namespace App\Entity;

use App\Repository\EmployeeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployeeRepository::class)]
#[ORM\Table(name: 'employee')]
#[ORM\HasLifecycleCallbacks]
class Employee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'employees')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $lunchBreakStart = null;

    #[ORM\Column(type: 'time', nullable: true)]
    private ?\DateTimeInterface $lunchBreakEnd = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $workingHours = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $portfolioPhotos = null;

    /** The user account that belongs to this employee (for employee login). */
    #[ORM\OneToOne(inversedBy: 'employee', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\OneToMany(mappedBy: 'employee', targetEntity: Appointment::class)]
    private Collection $appointments;

    /** @var Collection<int, \App\Entity\Service> */
    #[ORM\ManyToMany(targetEntity: \App\Entity\Service::class, mappedBy: 'employees')]
    private Collection $services;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->appointments = new ArrayCollection();
        $this->services     = new ArrayCollection();
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $v): static { $this->role = $v; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }

    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $v): static { $this->bio = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getWorkingHours(): ?array { return $this->workingHours; }
    public function setWorkingHours(?array $v): static { $this->workingHours = $v; return $this; }

    public function getPortfolioPhotos(): ?array { return $this->portfolioPhotos; }
    public function setPortfolioPhotos(?array $v): static { $this->portfolioPhotos = $v ?: null; return $this; }

    public function getLunchBreakStart(): ?\DateTimeInterface { return $this->lunchBreakStart; }
    public function setLunchBreakStart(?\DateTimeInterface $v): static { $this->lunchBreakStart = $v; return $this; }

    public function getLunchBreakEnd(): ?\DateTimeInterface { return $this->lunchBreakEnd; }
    public function setLunchBreakEnd(?\DateTimeInterface $v): static { $this->lunchBreakEnd = $v; return $this; }

    /** Convenience: returns lunch window as ["HH:MM", "HH:MM"] or null if not set. */
    public function getLunchBreak(): ?array
    {
        if (!$this->lunchBreakStart || !$this->lunchBreakEnd) {
            return null;
        }
        return [
            $this->lunchBreakStart->format('H:i'),
            $this->lunchBreakEnd->format('H:i'),
        ];
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $v): static { $this->user = $v; return $this; }

    /** @return Collection<int, Appointment> */
    public function getAppointments(): Collection { return $this->appointments; }

    /** @return Collection<int, \App\Entity\Service> */
    public function getServices(): Collection { return $this->services; }
}
