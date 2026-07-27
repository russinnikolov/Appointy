<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Entity\Employee;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared confirm/cancel business rules for a pending appointment — used by both the
 * business dashboard and the Telegram notification buttons, so a decision made from
 * either place follows the exact same capacity/cascade rules.
 */
class AppointmentDecisionService
{
    public function __construct(
        private readonly AppointmentRepository $apptRepo,
        private readonly EmployeeRepository $employeeRepo,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $t,
    ) {
    }

    /**
     * Confirms a pending appointment. Returns false (no change made) if the slot is
     * already at full capacity — callers should surface this back to whoever tried
     * to confirm rather than silently doing nothing.
     */
    public function confirm(Appointment $appt, ?Employee $employee = null): bool
    {
        $org = $appt->getOrganization();

        // Capacity = number of active employees in this org
        $capacity       = $this->employeeRepo->count(['organization' => $org, 'isActive' => true]);
        $confirmedCount = $this->apptRepo->countConfirmedAtSlot($org, $appt->getAppointmentDate(), $appt->getAppointmentTime());

        if ($capacity > 0 && $confirmedCount >= $capacity) {
            return false;
        }

        if ($employee !== null && $appt->getEmployee() === null) {
            $appt->setEmployee($employee);
        }

        $appt->setStatus(Appointment::STATUS_CONFIRMED);

        // When this confirmation fills the last available slot, cancel every other
        // pending appointment at the same date+time for this organisation.
        if ($capacity > 0 && $confirmedCount + 1 >= $capacity) {
            $others = $this->apptRepo->findPendingAtSlot(
                $org,
                $appt->getAppointmentDate(),
                $appt->getAppointmentTime(),
                $appt->getId()
            );
            foreach ($others as $other) {
                $other->setStatus(Appointment::STATUS_CANCELLED)
                      ->setCancellationNote($this->t->trans('flash.cancelled_capacity'));
            }
        }

        $this->em->flush();

        return true;
    }

    public function cancel(Appointment $appt, ?string $note = null, ?Employee $employee = null): void
    {
        if ($employee !== null && $appt->getEmployee() === null) {
            $appt->setEmployee($employee);
        }

        $appt->setStatus(Appointment::STATUS_CANCELLED)
             ->setCancellationNote($note ?: null);

        $this->em->flush();
    }
}
