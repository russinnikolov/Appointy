<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    public function findOneForPeriod(Subscription $subscription, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.subscription = :sub')
            ->andWhere('i.periodStart = :start')
            ->andWhere('i.periodEnd = :end')
            ->setParameter('sub', $subscription)
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Invoice[] */
    public function findPendingPastDue(\DateTimeImmutable $asOf): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.status = :status')
            ->andWhere('i.dueDate < :today')
            ->setParameter('status', Invoice::STATUS_PENDING)
            ->setParameter('today', $asOf)
            ->getQuery()
            ->getResult();
    }
}
