<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\Organization;
use App\Entity\Service;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Service::class);
    }

    /** @return Service[] All services for the org, with employees eagerly loaded. */
    public function findByOrganization(Organization $org): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.employees', 'e')->addSelect('e')
            ->andWhere('s.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active services visible to a specific employee:
     * those assigned to that employee + those assigned to ALL employees (empty set).
     * @return Service[]
     */
    public function findActiveForEmployee(Employee $employee): array
    {
        $all = $this->createQueryBuilder('s')
            ->leftJoin('s.employees', 'e')->addSelect('e')
            ->andWhere('s.organization = :org')
            ->andWhere('s.isActive = :active')
            ->setParameter('org', $employee->getOrganization())
            ->setParameter('active', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $all,
            fn(Service $s) => $s->isForAllEmployees() || $s->getEmployees()->contains($employee)
        ));
    }

    /**
     * Active services that are available to ALL employees (no specific assignment).
     * Used when client hasn't selected an employee.
     * @return Service[]
     */
    public function findActiveForAllEmployees(Organization $org): array
    {
        $all = $this->createQueryBuilder('s')
            ->leftJoin('s.employees', 'e')->addSelect('e')
            ->andWhere('s.organization = :org')
            ->andWhere('s.isActive = :active')
            ->setParameter('org', $org)
            ->setParameter('active', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($all, fn(Service $s) => $s->isForAllEmployees()));
    }
}
