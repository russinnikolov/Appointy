<?php

namespace App\Repository;

use App\Entity\NotificationChannel;
use App\Entity\Organization;
use App\Enum\NotificationChannelType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationChannel::class);
    }

    public function findOneActiveByOrgAndType(Organization $org, NotificationChannelType $type): ?NotificationChannel
    {
        return $this->findOneBy(['organization' => $org, 'type' => $type, 'isActive' => true]);
    }

    public function findOneByOrgAndType(Organization $org, NotificationChannelType $type): ?NotificationChannel
    {
        return $this->findOneBy(['organization' => $org, 'type' => $type]);
    }

    public function findOneByLinkToken(string $token): ?NotificationChannel
    {
        return $this->findOneBy(['linkToken' => $token]);
    }
}
