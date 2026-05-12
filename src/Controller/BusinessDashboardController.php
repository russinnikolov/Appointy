<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\BlockedPeriod;
use App\Entity\Employee;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\BlockedPeriodRepository;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business')]
class BusinessDashboardController extends AbstractController
{
    #[Route('', name: 'business_dashboard')]
    public function index(AppointmentRepository $repo, BlockedPeriodRepository $blockedRepo, EmployeeRepository $empRepo, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            $this->addFlash('danger', $t->trans('flash.no_organization'));
            return $this->redirectToRoute('home');
        }

        $appointments = $repo->findByOrganization($org);
        $apptData     = array_map(static fn(Appointment $a) => [
            'id'                => $a->getId(),
            'booker_name'       => $a->getBookerName(),
            'booker_phone'      => $a->getBookerPhone(),
            'guest'             => $a->isGuestBooking(),
            'date'              => $a->getAppointmentDate()->format('Y-m-d'),
            'time'              => $a->getAppointmentTime()->format('H:i'),
            'status'            => $a->getStatus(),
            'employee_name'     => $a->getEmployee()?->getName(),
            'employee_role'     => $a->getEmployee()?->getRole(),
            'employee_id'       => $a->getEmployee()?->getId(),
            'messaging_apps'    => $a->getClient()?->getMessagingApps() ?? [],
            'notes'             => $a->getNotes(),
            'cancel_note'       => $a->getCancellationNote(),
            'duration_minutes'  => $a->getDurationMinutes(),
            'service_name'      => $a->getService()?->getName(),
        ], $appointments);

        $blockedData = array_map(static fn(BlockedPeriod $b) => [
            'date'        => $b->getDate()->format('Y-m-d'),
            'start'       => $b->getStartTime()->format('H:i'),
            'end'         => $b->getEndTime()->format('H:i'),
            'label'       => $b->getLabel(),
            'employee_id' => $b->getEmployee()?->getId(),
        ], $blockedRepo->findByOrganizationOrdered($org));

        $activeEmployees = $empRepo->findBy(['organization' => $org, 'isActive' => true], ['name' => 'ASC']);

        $employeesJs = array_map(static fn(Employee $e) => [
            'id'   => $e->getId(),
            'name' => $e->getName(),
            'role' => $e->getRole(),
        ], $activeEmployees);

        return $this->render('business/dashboard.html.twig', [
            'organization'       => $org,
            'appointments_json'  => json_encode($apptData),
            'blocked_json'       => json_encode($blockedData),
            'counts'             => $repo->countByStatus($org),
            'employees'          => $activeEmployees,
            'org_capacity'       => count($activeEmployees),
            'org_employees_json' => json_encode($employeesJs),
        ]);
    }

    #[Route('/confirm/{id}', name: 'business_confirm', methods: ['POST'])]
    public function confirm(
        int $id,
        Request $request,
        AppointmentRepository $repo,
        EmployeeRepository $empRepo,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();
        $appt = $repo->find($id);

        if (!$appt || $appt->getOrganization() !== $org || $appt->getStatus() !== Appointment::STATUS_PENDING) {
            return $this->redirectToRoute('business_dashboard');
        }

        // Capacity = number of active employees in this org
        $capacity       = $empRepo->count(['organization' => $org, 'isActive' => true]);
        $confirmedCount = $repo->countConfirmedAtSlot($org, $appt->getAppointmentDate(), $appt->getAppointmentTime());

        // Guard: slot already full (should not happen via normal UI)
        if ($capacity > 0 && $confirmedCount >= $capacity) {
            $this->addFlash('danger', $t->trans('flash.slot_full'));
            return $this->redirectToRoute('business_dashboard');
        }

        // Optionally assign an employee if none was set and the owner picked one
        $empId = (int) $request->request->get('employee_id', 0);
        if ($empId && $appt->getEmployee() === null) {
            $emp = $empRepo->find($empId);
            if ($emp && $emp->getOrganization() === $org && $emp->isActive()) {
                $appt->setEmployee($emp);
            }
        }

        $appt->setStatus(Appointment::STATUS_CONFIRMED);

        // When this confirmation fills the last available slot, cancel every other
        // pending appointment at the same date+time for this organisation.
        if ($capacity > 0 && $confirmedCount + 1 >= $capacity) {
            $others = $repo->findPendingAtSlot(
                $org,
                $appt->getAppointmentDate(),
                $appt->getAppointmentTime(),
                $appt->getId()
            );
            foreach ($others as $other) {
                $other->setStatus(Appointment::STATUS_CANCELLED)
                      ->setCancellationNote($t->trans('flash.cancelled_capacity'));
            }
        }

        $em->flush();
        $this->addFlash('success', $t->trans('flash.appointment_confirmed'));
        return $this->redirectToRoute('business_dashboard');
    }

    #[Route('/cancel/{id}', name: 'business_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, AppointmentRepository $repo, EmployeeRepository $empRepo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();
        $appt = $repo->find($id);

        if ($appt && $appt->getOrganization() === $org
            && $appt->getStatus() !== Appointment::STATUS_CANCELLED) {

            // Optionally assign an employee if none was set and the owner picked one
            $empId = (int) $request->request->get('employee_id', 0);
            if ($empId && $appt->getEmployee() === null) {
                $emp = $empRepo->find($empId);
                if ($emp && $emp->getOrganization() === $org && $emp->isActive()) {
                    $appt->setEmployee($emp);
                }
            }

            $note = trim($request->request->get('cancellation_note', ''));
            $appt->setStatus(Appointment::STATUS_CANCELLED)
                 ->setCancellationNote($note ?: null);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.appointment_cancelled'));
        }

        return $this->redirectToRoute('business_dashboard');
    }

    #[Route('/appointment/{id}/duration', name: 'business_set_duration', methods: ['POST'])]
    public function setDuration(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if (!$appt || $appt->getOrganization() !== $user->getOrganization()
            || $appt->getStatus() === Appointment::STATUS_CANCELLED) {
            return new JsonResponse(['ok' => false], 403);
        }

        $minutes = (int) $request->request->get('minutes', 0);
        $appt->setDurationMinutes($minutes > 0 ? $minutes : null);

        $cancelled = [];
        foreach ($request->request->all('cancel_ids') as $cancelId) {
            $other = $repo->find((int) $cancelId);
            if ($other && $other->getOrganization() === $user->getOrganization()
                && $other->getStatus() === Appointment::STATUS_PENDING) {
                $other->setStatus(Appointment::STATUS_CANCELLED);
                $cancelled[] = (int) $cancelId;
            }
        }

        $em->flush();

        return new JsonResponse([
            'ok'               => true,
            'duration_minutes' => $appt->getDurationMinutes(),
            'cancelled'        => $cancelled,
        ]);
    }
}
