<?php

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /** @return Organization[] */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.category = :cat')
            ->setParameter('cat', $category)
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Organization[] */
    public function search(string $term): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.name LIKE :t OR o.category LIKE :t OR o.description LIKE :t')
            ->setParameter('t', '%' . $term . '%')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
