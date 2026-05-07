<?php

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\Employee;
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
            ->leftJoin('a.employee', 'e')
            ->addSelect('e')
            ->andWhere('a.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('a.appointmentDate', 'DESC')
            ->addOrderBy('a.appointmentTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Appointment[] */
    public function findByEmployee(Employee $employee): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.employee = :emp')
            ->setParameter('emp', $employee)
            ->orderBy('a.appointmentDate', 'DESC')
            ->addOrderBy('a.appointmentTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns an existing non-cancelled appointment for the same employee/date/time.
     * Pass $excludeId to ignore the appointment being rescheduled.
     */
    public function findConflict(Employee $employee, \DateTimeInterface $date, \DateTimeInterface $time, ?int $excludeId = null): ?Appointment
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.employee = :emp')
            ->andWhere('a.appointmentDate = :date')
            ->andWhere('a.appointmentTime = :time')
            ->andWhere('a.status != :cancelled')
            ->setParameter('emp', $employee)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('time', $time->format('H:i:s'))
            ->setParameter('cancelled', Appointment::STATUS_CANCELLED)
            ->setMaxResults(1);

        if ($excludeId !== null) {
            $qb->andWhere('a.id != :id')->setParameter('id', $excludeId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Returns array of "HH:MM" strings that are booked for the given employee on the given date.
     * @return string[]
     */
    public function findBusySlots(Employee $employee, string $date): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.appointmentTime')
            ->andWhere('a.employee = :emp')
            ->andWhere('a.appointmentDate = :date')
            ->andWhere('a.status != :cancelled')
            ->setParameter('emp', $employee)
            ->setParameter('date', $date)
            ->setParameter('cancelled', Appointment::STATUS_CANCELLED)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn($row) => $row['appointmentTime'] instanceof \DateTimeInterface
                ? $row['appointmentTime']->format('H:i')
                : substr((string) $row['appointmentTime'], 0, 5),
            $rows
        );
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
