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

    public function countByClient(User $client): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.client = :client')
            ->setParameter('client', $client)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Appointment[] */
    public function findByClientPaginated(User $client, int $page, int $perPage = 10): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.client = :client')
            ->setParameter('client', $client)
            ->orderBy('a.appointmentDate', 'DESC')
            ->addOrderBy('a.appointmentTime', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByOrg(Organization $org, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.organization = :org')
            ->setParameter('org', $org);

        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return Appointment[] */
    public function findByOrgPaginated(Organization $org, int $page, int $perPage = 10, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.employee', 'e')->addSelect('e')
            ->leftJoin('a.client',   'u')->addSelect('u')
            ->andWhere('a.organization = :org')
            ->setParameter('org', $org)
            ->orderBy('a.appointmentDate', 'DESC')
            ->addOrderBy('a.appointmentTime', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
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
     * Filtered report query for a given organization.
     * @return Appointment[]
     */
    public function findForReport(
        Organization $org,
        \DateTimeInterface $dateFrom,
        \DateTimeInterface $dateTo,
        ?string $status = null,
        ?Employee $employee = null
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.employee', 'e')->addSelect('e')
            ->leftJoin('a.client', 'c')->addSelect('c')
            ->andWhere('a.organization = :org')
            ->andWhere('a.appointmentDate >= :from')
            ->andWhere('a.appointmentDate <= :to')
            ->setParameter('org', $org)
            ->setParameter('from', $dateFrom)
            ->setParameter('to', $dateTo)
            ->orderBy('a.appointmentDate', 'ASC')
            ->addOrderBy('a.appointmentTime', 'ASC');

        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if ($employee) {
            $qb->andWhere('a.employee = :emp')->setParameter('emp', $employee);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns an existing non-cancelled appointment for the same employee/date/time.
     * Pass $excludeId to ignore the appointment being rescheduled.
     */
    /**
     * Returns a conflicting appointment, accounting for custom durationMinutes.
     * A conflict exists if the requested slot falls within any existing appointment's time range.
     */
    public function findConflict(Employee $employee, \DateTimeInterface $date, \DateTimeInterface $time, ?int $excludeId = null, int $timeStep = 30): ?Appointment
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.employee = :emp')
            ->andWhere('a.appointmentDate = :date')
            ->andWhere('a.status != :cancelled')
            ->setParameter('emp', $employee)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('cancelled', Appointment::STATUS_CANCELLED);

        if ($excludeId !== null) {
            $qb->andWhere('a.id != :id')->setParameter('id', $excludeId);
        }

        $existing = $qb->getQuery()->getResult();

        $reqMin    = (int) $time->format('H') * 60 + (int) $time->format('i');
        $reqEndMin = $reqMin + $timeStep;

        foreach ($existing as $a) {
            $aMin    = (int) $a->getAppointmentTime()->format('H') * 60
                     + (int) $a->getAppointmentTime()->format('i');
            $aEndMin = $aMin + ($a->getDurationMinutes() ?? $timeStep);

            if ($reqMin < $aEndMin && $reqEndMin > $aMin) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Returns all "HH:MM" slot strings occupied by appointments for the given employee/date.
     * Appointments with durationMinutes > timeStep expand into multiple busy slots.
     * @return string[]
     */
    public function findBusySlots(Employee $employee, string $date, int $timeStep = 30): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.appointmentTime, a.durationMinutes')
            ->andWhere('a.employee = :emp')
            ->andWhere('a.appointmentDate = :date')
            ->andWhere('a.status != :cancelled')
            ->setParameter('emp', $employee)
            ->setParameter('date', $date)
            ->setParameter('cancelled', Appointment::STATUS_CANCELLED)
            ->getQuery()
            ->getResult();

        $slots = [];
        foreach ($rows as $row) {
            $startStr = $row['appointmentTime'] instanceof \DateTimeInterface
                ? $row['appointmentTime']->format('H:i')
                : substr((string) $row['appointmentTime'], 0, 5);

            [$h, $m]  = array_map('intval', explode(':', $startStr));
            $startMin = $h * 60 + $m;
            $duration = $row['durationMinutes'] ?? $timeStep;

            for ($t = $startMin; $t < $startMin + $duration; $t += $timeStep) {
                $slots[] = str_pad((string) intdiv($t, 60), 2, '0', STR_PAD_LEFT)
                         . ':' . str_pad((string) ($t % 60), 2, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * Number of CONFIRMED appointments that start at exactly this date+time for the org.
     */
    public function countConfirmedAtSlot(Organization $org, \DateTimeInterface $date, \DateTimeInterface $time): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.organization = :org')
            ->andWhere('a.appointmentDate = :date')
            ->andWhere('a.appointmentTime = :time')
            ->andWhere('a.status = :confirmed')
            ->setParameter('org', $org)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('time', $time->format('H:i:s'))
            ->setParameter('confirmed', Appointment::STATUS_CONFIRMED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All PENDING appointments at exactly this date+time for the org, excluding one ID.
     * @return Appointment[]
     */
    public function findPendingAtSlot(Organization $org, \DateTimeInterface $date, \DateTimeInterface $time, int $excludeId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.organization = :org')
            ->andWhere('a.appointmentDate = :date')
            ->andWhere('a.appointmentTime = :time')
            ->andWhere('a.status = :pending')
            ->andWhere('a.id != :id')
            ->setParameter('org', $org)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setParameter('time', $time->format('H:i:s'))
            ->setParameter('pending', Appointment::STATUS_PENDING)
            ->setParameter('id', $excludeId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns distinct clients (User) who have at least one booking with the org,
     * sorted by their name alphabetically.
     *
     * @return User[]
     */
    public function findDistinctClientsByOrg(Organization $org): array
    {
        // Step 1 — collect distinct client IDs as scalars (root entity = Appointment, safe)
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.client) AS clientId')
            ->andWhere('a.organization = :org')
            ->andWhere('a.client IS NOT NULL')
            ->setParameter('org', $org)
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        $ids = array_values(array_unique(array_column($rows, 'clientId')));

        if (empty($ids)) {
            return [];
        }

        // Step 2 — load User entities with User as the root alias (no DQL restriction)
        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Count of CONFIRMED appointments for an org within a date range (inclusive) — used for pay-per-reservation billing. */
    public function countConfirmedForOrganizationBetween(Organization $org, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.organization = :org')
            ->andWhere('a.status = :confirmed')
            ->andWhere('a.appointmentDate >= :start')
            ->andWhere('a.appointmentDate <= :end')
            ->setParameter('org', $org)
            ->setParameter('confirmed', Appointment::STATUS_CONFIRMED)
            ->setParameter('start', $periodStart->format('Y-m-d'))
            ->setParameter('end', $periodEnd->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
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
