<?php

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceRepository::class)]
#[ORM\Table(name: 'service')]
class Service
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'services')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Stored as DECIMAL string to avoid float rounding issues. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $price = null;

    // ── Auto-translated fields ────────────────────────────────────────────────
    #[ORM\Column(length: 5, options: ['default' => 'bg'])]
    private string $sourceLocale = 'bg';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nameEn = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nameBg = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descriptionEn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descriptionBg = null;

    #[ORM\Column(type: 'integer')]
    private int $durationMinutes = 30;

    #[ORM\Column]
    private bool $isActive = true;

    /**
     * Specific employees who can perform this service.
     * When EMPTY the service is available to ALL employees.
     *
     * @var Collection<int, Employee>
     */
    #[ORM\ManyToMany(targetEntity: Employee::class, inversedBy: 'services')]
    #[ORM\JoinTable(name: 'service_employee')]
    private Collection $employees;

    public function __construct()
    {
        $this->employees = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $v): static { $this->price = ($v !== null && $v !== '') ? $v : null; return $this; }

    public function getPriceFloat(): ?float { return $this->price !== null ? (float) $this->price : null; }

    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $v): static { $this->durationMinutes = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    /** @return Collection<int, Employee> */
    public function getEmployees(): Collection { return $this->employees; }

    public function addEmployee(Employee $emp): static
    {
        if (!$this->employees->contains($emp)) {
            $this->employees->add($emp);
        }
        return $this;
    }

    public function removeEmployee(Employee $emp): static
    {
        $this->employees->removeElement($emp);
        return $this;
    }

    /** True when no specific employees are assigned → available to everyone. */
    public function isForAllEmployees(): bool
    {
        return $this->employees->isEmpty();
    }

    /** Returns employee IDs assigned to this service (empty = all). */
    public function getEmployeeIds(): array
    {
        return $this->employees->map(fn(Employee $e) => $e->getId())->toArray();
    }

    // ── Translation helpers ───────────────────────────────────────────────────

    public function getSourceLocale(): string { return $this->sourceLocale; }
    public function setSourceLocale(string $v): static { $this->sourceLocale = $v; return $this; }

    public function getNameEn(): ?string { return $this->nameEn; }
    public function setNameEn(?string $v): static { $this->nameEn = $v; return $this; }

    public function getNameBg(): ?string { return $this->nameBg; }
    public function setNameBg(?string $v): static { $this->nameBg = $v; return $this; }

    public function getDescriptionEn(): ?string { return $this->descriptionEn; }
    public function setDescriptionEn(?string $v): static { $this->descriptionEn = $v; return $this; }

    public function getDescriptionBg(): ?string { return $this->descriptionBg; }
    public function setDescriptionBg(?string $v): static { $this->descriptionBg = $v; return $this; }

    /** Generic getter used by the Twig extension: field = 'name' | 'description', locale = 'en' | 'bg' */
    public function getLocalized(string $field, string $locale): ?string
    {
        $getter = 'get' . ucfirst($field) . ucfirst($locale); // e.g. getNameEn
        return method_exists($this, $getter) ? $this->$getter() : null;
    }

    /** Generic setter used by TranslationApiService */
    public function setTranslatedName(string $locale, ?string $value): static
    {
        $setter = 'setName' . ucfirst($locale);
        if (method_exists($this, $setter)) {
            $this->$setter($value);
        }
        return $this;
    }

    public function setTranslatedDescription(string $locale, ?string $value): static
    {
        $setter = 'setDescription' . ucfirst($locale);
        if (method_exists($this, $setter)) {
            $this->$setter($value);
        }
        return $this;
    }
}
