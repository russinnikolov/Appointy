<?php

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppointmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    /** @return Appointment[] */
    public function findByClient(User $client): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.client = :client')
            ->setParameter('client', $client)
            ->orderBy('a.appointmentDate', 'DESC')
            ->addOrderBy('a.appointmentTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Appointment[] */
    public function findByOrganization(Organization $org): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('a.appointmentDate', 'DESC')
            ->addOrderBy('a.appointmentTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{pending: int, confirmed: int, cancelled: int} */
    public function countByStatus(Organization $org): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.status, COUNT(a.id) as cnt')
            ->andWhere('a.organization = :org')
            ->setParameter('org', $org)
            ->groupBy('a.status')
            ->getQuery()
            ->getResult();

        $counts = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
        return $counts;
    }
}
