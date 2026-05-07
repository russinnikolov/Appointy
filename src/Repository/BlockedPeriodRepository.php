<?php

namespace App\Repository;

use App\Entity\BlockedPeriod;
use App\Entity\Employee;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlockedPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockedPeriod::class);
    }

    /**
     * Returns all blocked periods that affect the given employee on the given date.
     * Includes org-wide blocks (employee = null) and employee-specific blocks.
     *
     * @return BlockedPeriod[]
     */
    public function findForEmployee(Organization $org, Employee $employee, string $date): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.organization = :org')
            ->andWhere('b.date = :date')
            ->andWhere('b.employee IS NULL OR b.employee = :emp')
            ->setParameter('org', $org)
            ->setParameter('date', $date)
            ->setParameter('emp', $employee)
            ->orderBy('b.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return BlockedPeriod[] */
    public function findByOrganizationOrdered(Organization $org): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('b.date', 'ASC')
            ->addOrderBy('b.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
