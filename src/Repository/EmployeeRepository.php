<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmployeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employee::class);
    }

    /** @return Employee[] */
    public function findActiveByOrganization(Organization $org): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.organization = :org')
            ->andWhere('e.isActive = true')
            ->setParameter('org', $org)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
