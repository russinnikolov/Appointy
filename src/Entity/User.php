<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'This email is already registered.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const TYPE_CLIENT   = 'client';
    public const TYPE_BUSINESS = 'business';
    public const TYPE_EMPLOYEE = 'employee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private string $password = '';

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

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_CLIENT;

    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Appointment::class, orphanRemoval: true)]
    private Collection $appointments;

    #[ORM\ManyToMany(targetEntity: Organization::class)]
    #[ORM\JoinTable(name: 'client_favourite_organization')]
    private Collection $favouriteOrganizations;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Employee::class)]
    private ?Employee $employee = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarFilename = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $mustChangePassword = false;

    /** Preferred UI locale (bg|en). NULL means auto-detect on each session. */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $messagingApps = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->appointments            = new ArrayCollection();
        $this->favouriteOrganizations  = new ArrayCollection();
        $this->createdAt               = new \DateTimeImmutable();
        $this->updatedAt               = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $v): static { $this->email = $v; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $v): static { $this->password = $v; return $this; }

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

    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }

    public function isClient(): bool { return $this->type === self::TYPE_CLIENT; }
    public function isBusiness(): bool { return $this->type === self::TYPE_BUSINESS; }

    public function getOrganization(): ?Organization { return $this->organization; }
    public function setOrganization(?Organization $v): static { $this->organization = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Appointment> */
    public function getAppointments(): Collection { return $this->appointments; }

    /** @return Collection<int, Organization> */
    public function getFavouriteOrganizations(): Collection { return $this->favouriteOrganizations; }

    public function addFavouriteOrganization(Organization $org): static
    {
        if (!$this->favouriteOrganizations->contains($org)) {
            $this->favouriteOrganizations->add($org);
        }
        return $this;
    }

    public function removeFavouriteOrganization(Organization $org): static
    {
        $this->favouriteOrganizations->removeElement($org);
        return $this;
    }

    public function isFavouriteOrganization(Organization $org): bool
    {
        return $this->favouriteOrganizations->contains($org);
    }

    public function getEmployee(): ?Employee { return $this->employee; }

    public function getAvatarFilename(): ?string { return $this->avatarFilename; }
    public function setAvatarFilename(?string $v): static { $this->avatarFilename = $v; return $this; }

    public function isMustChangePassword(): bool { return $this->mustChangePassword; }
    public function setMustChangePassword(bool $v): static { $this->mustChangePassword = $v; return $this; }

    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $v): static { $this->locale = $v; return $this; }

    public const MESSAGING_APPS = ['whatsapp', 'telegram', 'viber', 'sms'];

    public function getMessagingApps(): array { return $this->messagingApps ?? []; }
    public function setMessagingApps(array $v): static { $this->messagingApps = $v ?: null; return $this; }

    public function getRoles(): array
    {
        return match($this->type) {
            self::TYPE_BUSINESS => ['ROLE_BUSINESS'],
            self::TYPE_EMPLOYEE => ['ROLE_EMPLOYEE'],
            default             => ['ROLE_CLIENT'],
        };
    }

    public function getUserIdentifier(): string { return $this->email ?? ''; }
    public function eraseCredentials(): void {}
}
