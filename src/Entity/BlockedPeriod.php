<?php

namespace App\Entity;

use App\Repository\BlockedPeriodRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlockedPeriodRepository::class)]
#[ORM\Table(name: 'blocked_period')]
class BlockedPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blockedPeriods')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    /** null = applies to every employee in the org */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Employee $employee = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: 'time')]
    private ?\DateTimeInterface $startTime = null;

    #[ORM\Column(type: 'time')]
    private ?\DateTimeInterface $endTime = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(Organization $v): static { $this->organization = $v; return $this; }

    public function getEmployee(): ?Employee { return $this->employee; }
    public function setEmployee(?Employee $v): static { $this->employee = $v; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(\DateTimeInterface $v): static { $this->date = $v; return $this; }

    public function getStartTime(): ?\DateTimeInterface { return $this->startTime; }
    public function setStartTime(\DateTimeInterface $v): static { $this->startTime = $v; return $this; }

    public function getEndTime(): ?\DateTimeInterface { return $this->endTime; }
    public function setEndTime(\DateTimeInterface $v): static { $this->endTime = $v; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): static { $this->label = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
