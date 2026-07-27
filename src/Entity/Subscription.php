<?php

namespace App\Entity;

use App\Enum\PlanCode;
use App\Repository\SubscriptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    public const STATUS_TRIALING  = 'trialing';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAST_DUE  = 'past_due';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELED  = 'canceled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'subscription', targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 30, enumType: PlanCode::class)]
    private PlanCode $plan;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_TRIALING])]
    private string $status = self::STATUS_TRIALING;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $currentPeriodStart = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(mappedBy: 'subscription', targetEntity: Invoice::class, orphanRemoval: true)]
    private Collection $invoices;

    public function __construct()
    {
        $this->invoices  = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isTrialing(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trialEndsAt !== null
            && $this->trialEndsAt > new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getPlan(): PlanCode { return $this->plan; }
    public function setPlan(PlanCode $v): static { $this->plan = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getTrialEndsAt(): ?\DateTimeImmutable { return $this->trialEndsAt; }
    public function setTrialEndsAt(?\DateTimeImmutable $v): static { $this->trialEndsAt = $v; return $this; }

    public function getCurrentPeriodStart(): ?\DateTimeImmutable { return $this->currentPeriodStart; }
    public function setCurrentPeriodStart(?\DateTimeImmutable $v): static { $this->currentPeriodStart = $v; return $this; }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable { return $this->currentPeriodEnd; }
    public function setCurrentPeriodEnd(?\DateTimeImmutable $v): static { $this->currentPeriodEnd = $v; return $this; }

    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $v): static { $this->stripeCustomerId = $v; return $this; }

    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }
    public function setStripeSubscriptionId(?string $v): static { $this->stripeSubscriptionId = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Invoice> */
    public function getInvoices(): Collection { return $this->invoices; }
}
