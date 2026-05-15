<?php

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\HasLifecycleCallbacks]
class Organization
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zipCode = null;

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

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $workingHours = null;

    #[ORM\Column(type: 'integer', options: ['default' => 15])]
    private int $timeStep = 15;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $nonstop = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: User::class)]
    private Collection $users;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: Employee::class, orphanRemoval: true)]
    private Collection $employees;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: Appointment::class, orphanRemoval: true)]
    private Collection $appointments;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: \App\Entity\BlockedPeriod::class, orphanRemoval: true)]
    private Collection $blockedPeriods;

    #[ORM\OneToMany(mappedBy: 'organization', targetEntity: \App\Entity\Service::class, orphanRemoval: true)]
    private Collection $services;

    /** Clients whose bookings are auto-confirmed for this org. */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'org_trusted_client')]
    private Collection $trustedClients;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->workingHours   = self::defaultWorkingHours();
        $this->users          = new ArrayCollection();
        $this->employees      = new ArrayCollection();
        $this->appointments   = new ArrayCollection();
        $this->blockedPeriods = new ArrayCollection();
        $this->services       = new ArrayCollection();
        $this->trustedClients = new ArrayCollection();
        $this->createdAt      = new \DateTimeImmutable();
        $this->updatedAt      = new \DateTimeImmutable();
    }

    public static function defaultWorkingHours(): array
    {
        return [
            'monday'    => ['enabled' => true,  'open' => '09:00', 'close' => '18:00'],
            'tuesday'   => ['enabled' => true,  'open' => '09:00', 'close' => '18:00'],
            'wednesday' => ['enabled' => true,  'open' => '09:00', 'close' => '18:00'],
            'thursday'  => ['enabled' => true,  'open' => '09:00', 'close' => '18:00'],
            'friday'    => ['enabled' => true,  'open' => '09:00', 'close' => '17:00'],
            'saturday'  => ['enabled' => false, 'open' => '10:00', 'close' => '14:00'],
            'sunday'    => ['enabled' => false, 'open' => '10:00', 'close' => '14:00'],
        ];
    }

    /** Returns array of PHP day-of-week numbers (0=Sun…6=Sat) that are disabled. */
    public function getDisabledWeekdays(): array
    {
        if ($this->nonstop) {
            return [];
        }
        $phpDayMap = [
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6,
        ];
        $disabled = [];
        foreach ($this->getWorkingHours() as $day => $cfg) {
            if (empty($cfg['enabled'])) {
                $disabled[] = $phpDayMap[$day];
            }
        }
        return $disabled;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = $v; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $v): static { $this->address = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v; return $this; }

    public function getZipCode(): ?string { return $this->zipCode; }
    public function setZipCode(?string $v): static { $this->zipCode = $v; return $this; }

    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $v): static { $this->latitude = $v; return $this; }

    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $v): static { $this->longitude = $v; return $this; }

    public function getFullAddress(): string
    {
        return implode(', ', array_filter([$this->address, $this->city, $this->zipCode, $this->country]));
    }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }

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
        $getter = 'get' . ucfirst($field) . ucfirst($locale);
        return method_exists($this, $getter) ? $this->$getter() : null;
    }

    public function setTranslatedName(string $locale, ?string $value): static
    {
        $setter = 'setName' . ucfirst($locale);
        if (method_exists($this, $setter)) { $this->$setter($value); }
        return $this;
    }

    public function setTranslatedDescription(string $locale, ?string $value): static
    {
        $setter = 'setDescription' . ucfirst($locale);
        if (method_exists($this, $setter)) { $this->$setter($value); }
        return $this;
    }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $v): static { $this->category = $v; return $this; }

    public function getWorkingHours(): array { return $this->workingHours ?? self::defaultWorkingHours(); }
    public function setWorkingHours(array $v): static { $this->workingHours = $v; return $this; }

    public function getTimeStep(): int { return $this->timeStep; }
    public function setTimeStep(int $v): static { $this->timeStep = $v; return $this; }

    public function isNonstop(): bool { return $this->nonstop; }
    public function setNonstop(bool $v): static { $this->nonstop = $v; return $this; }

    public function getLogoFilename(): ?string { return $this->logoFilename; }
    public function setLogoFilename(?string $v): static { $this->logoFilename = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, User> */
    public function getUsers(): Collection { return $this->users; }

    /** @return Collection<int, Employee> */
    public function getEmployees(): Collection { return $this->employees; }

    /** @return Collection<int, Appointment> */
    public function getAppointments(): Collection { return $this->appointments; }

    /** @return Collection<int, \App\Entity\Service> */
    public function getServices(): Collection { return $this->services; }

    /** @return Collection<int, User> */
    public function getTrustedClients(): Collection { return $this->trustedClients; }

    public function addTrustedClient(User $user): static
    {
        if (!$this->trustedClients->contains($user)) {
            $this->trustedClients->add($user);
        }
        return $this;
    }

    public function removeTrustedClient(User $user): static
    {
        $this->trustedClients->removeElement($user);
        return $this;
    }

    public function isTrustedClient(User $user): bool
    {
        return $this->trustedClients->contains($user);
    }
}
