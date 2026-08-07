<?php

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /** @return Subscription[] */
    public function findExpiredTrials(\DateTimeImmutable $asOf): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.trialEndsAt < :now')
            ->setParameter('status', Subscription::STATUS_TRIALING)
            ->setParameter('now', $asOf)
            ->getQuery()
            ->getResult();
    }
}
