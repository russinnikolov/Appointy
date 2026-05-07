<?php

namespace App\Entity;

use App\Repository\PageViewRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PageViewRepository::class)]
#[ORM\Table(name: 'page_view')]
class PageView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $browser = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $os = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $device = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referer = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getIp(): ?string { return $this->ip; }
    public function setIp(?string $v): static { $this->ip = $v; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = $v; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }
    public function getBrowser(): ?string { return $this->browser; }
    public function setBrowser(?string $v): static { $this->browser = $v; return $this; }
    public function getOs(): ?string { return $this->os; }
    public function setOs(?string $v): static { $this->os = $v; return $this; }
    public function getDevice(): ?string { return $this->device; }
    public function setDevice(?string $v): static { $this->device = $v; return $this; }
    public function getRoute(): ?string { return $this->route; }
    public function setRoute(?string $v): static { $this->route = $v; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $this->url = $v; return $this; }
    public function getReferer(): ?string { return $this->referer; }
    public function setReferer(?string $v): static { $this->referer = $v; return $this; }
    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $v): static { $this->userId = $v; return $this; }
    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $v): static { $this->locale = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
