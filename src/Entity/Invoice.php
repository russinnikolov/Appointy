<?php

namespace App\Entity;

use App\Enum\PlanCode;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoice')]
class Invoice
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_OVERDUE = 'overdue';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Subscription $subscription = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Organization $organization = null;

    #[ORM\Column(length: 30, enumType: PlanCode::class)]
    private PlanCode $plan;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column]
    private int $reservationCount = 0;

    #[ORM\Column]
    private int $employeeCountAtBilling = 0;

    #[ORM\Column]
    private int $amountDueCents = 0;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $v): static { $this->subscription = $v; return $this; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getPlan(): PlanCode { return $this->plan; }
    public function setPlan(PlanCode $v): static { $this->plan = $v; return $this; }

    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $v): static { $this->periodStart = $v; return $this; }

    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $v): static { $this->periodEnd = $v; return $this; }

    public function getReservationCount(): int { return $this->reservationCount; }
    public function setReservationCount(int $v): static { $this->reservationCount = $v; return $this; }

    public function getEmployeeCountAtBilling(): int { return $this->employeeCountAtBilling; }
    public function setEmployeeCountAtBilling(int $v): static { $this->employeeCountAtBilling = $v; return $this; }

    public function getAmountDueCents(): int { return $this->amountDueCents; }
    public function setAmountDueCents(int $v): static { $this->amountDueCents = $v; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $v): static { $this->currency = $v; return $this; }

    public function getDueDate(): \DateTimeImmutable { return $this->dueDate; }
    public function setDueDate(\DateTimeImmutable $v): static { $this->dueDate = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getStripeInvoiceId(): ?string { return $this->stripeInvoiceId; }
    public function setStripeInvoiceId(?string $v): static { $this->stripeInvoiceId = $v; return $this; }

    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $v): static { $this->stripePaymentIntentId = $v; return $this; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $v): static { $this->paidAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
