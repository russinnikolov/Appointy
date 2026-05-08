<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_CLIENT')]
#[Route('/client')]
class ClientDashboardController extends AbstractController
{
    #[Route('', name: 'client_dashboard')]
    public function index(AppointmentRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $appointments = $repo->findByClient($user);
        $apptData     = array_map(static fn(Appointment $a) => [
            'id'          => $a->getId(),
            'org_name'    => $a->getOrganization()->getName(),
            'org_id'      => $a->getOrganization()->getId(),
            'date'        => $a->getAppointmentDate()->format('Y-m-d'),
            'time'        => $a->getAppointmentTime()->format('H:i'),
            'status'      => $a->getStatus(),
            'employee'    => $a->getEmployee()?->getName(),
            'emp_role'    => $a->getEmployee()?->getRole(),
            'emp_phone'   => $a->getEmployee()?->getPhone(),
            'org_phone'   => $a->getOrganization()->getPhone(),
            'notes'       => $a->getNotes(),
            'cancel_note' => $a->getCancellationNote(),
        ], $appointments);

        return $this->render('client/dashboard.html.twig', [
            'appointments_json' => json_encode($apptData),
            'has_appointments'  => !empty($appointments),
        ]);
    }

    #[Route('/organizations', name: 'client_organizations')]
    public function organizations(Request $request, OrganizationRepository $repo): Response
    {
        $search = trim($request->query->get('q', ''));

        $organizations = $search
            ? $repo->search($search)
            : $repo->findBy([], ['name' => 'ASC']);

        return $this->render('client/organizations.html.twig', [
            'organizations' => $organizations,
            'search'        => $search,
        ]);
    }

    #[Route('/book/{id}', name: 'client_book')]
    public function book(
        int $id,
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        $org = $orgRepo->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found.');
        }

        /** @var User $user */
        $user      = $this->getUser();
        $employees = $empRepo->findActiveByOrganization($org);
        $error     = null;

        if ($request->isMethod('POST')) {
            $date       = $request->request->get('date', '');
            $time       = $request->request->get('time', '');
            $notes      = trim($request->request->get('notes', ''));
            $employeeId = $request->request->get('employee_id');
            $employee   = null;

            if ($employeeId) {
                $employee = $empRepo->find((int) $employeeId);
                if (!$employee || $employee->getOrganization() !== $org) {
                    $employee = null;
                }
            }

            $step  = $org->getTimeStep();
            $allowedMinutes = [];
            for ($m = 0; $m < 60; $m += $step) {
                $allowedMinutes[] = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            }

            if (!$date || !$time) {
                $error = 'Please select both a date and a time.';
            } elseif (!in_array(substr($time, 3, 2), $allowedMinutes, true)) {
                $error = 'Please select a valid time slot.';
            } elseif (new \DateTime($date) < new \DateTime('today')) {
                $error = 'Appointment date cannot be in the past.';
            } elseif ($employee && $apptRepo->findConflict($employee, new \DateTime($date), new \DateTime($time), null, $org->getTimeStep())) {
                $error = $employee->getName() . ' already has an appointment at that time. Please choose a different time.';
            } else {
                $appt = new Appointment();
                $appt->setClient($user)
                     ->setOrganization($org)
                     ->setEmployee($employee)
                     ->setAppointmentDate(new \DateTime($date))
                     ->setAppointmentTime(new \DateTime($time))
                     ->setNotes($notes ?: null);

                $em->persist($appt);
                $em->flush();

                $this->addFlash('success', $t->trans('flash.appointment_booked'));
                return $this->redirectToRoute('client_dashboard');
            }
        }

        return $this->render('client/book.html.twig', [
            'organization' => $org,
            'employees'    => $employees,
            'error'        => $error,
        ]);
    }

    #[Route('/cancel/{id}', name: 'client_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if ($appt && $appt->getClient() === $user && $appt->getStatus() === Appointment::STATUS_PENDING) {
            $note = trim($request->request->get('cancellation_note', ''));
            $appt->setStatus(Appointment::STATUS_CANCELLED)
                 ->setCancellationNote($note ?: null);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.appointment_cancelled'));
        }

        return $this->redirectToRoute('client_dashboard');
    }
}
