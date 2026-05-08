<?php

namespace App\Controller;

use App\Repository\AppointmentRepository;
use App\Repository\BlockedPeriodRepository;
use App\Repository\EmployeeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    #[Route('/busy-slots', name: 'api_busy_slots')]
    public function busySlots(
        Request $request,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        BlockedPeriodRepository $blockedRepo
    ): JsonResponse {
        $employeeId = (int) $request->query->get('employee_id', 0);
        $date       = $request->query->get('date', '');

        if (!$employeeId || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json(['booked' => [], 'blocked' => []]);
        }

        $employee = $empRepo->find($employeeId);
        if (!$employee) {
            return $this->json(['booked' => [], 'blocked' => []]);
        }

        $timeStep = $employee->getOrganization()->getTimeStep();
        $booked   = $apptRepo->findBusySlots($employee, $date, $timeStep);
        $periods = $blockedRepo->findForEmployee($employee->getOrganization(), $employee, $date);

        $blocked = array_map(static fn($p) => [
            'start' => $p->getStartTime()->format('H:i'),
            'end'   => $p->getEndTime()->format('H:i'),
            'label' => $p->getLabel(),
        ], $periods);

        return $this->json(['booked' => $booked, 'blocked' => $blocked]);
    }
}
