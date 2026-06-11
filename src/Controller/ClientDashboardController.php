<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\OrganizationRepository;
use App\Repository\ServiceRepository;
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
            'service_name' => $a->getService()?->getName(),
            'notes'        => $a->getNotes(),
            'cancel_note'  => $a->getCancellationNote(),
        ], $appointments);

        return $this->render('client/dashboard.html.twig', [
            'appointments_json' => json_encode($apptData, JSON_HEX_TAG),
            'has_appointments'  => !empty($appointments),
        ]);
    }

    #[Route('/organizations', name: 'client_organizations')]
    public function organizations(Request $request, OrganizationRepository $repo): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $search = trim($request->query->get('q', ''));

        $organizations = $search
            ? $repo->search($search)
            : $repo->findBy([], ['name' => 'ASC']);

        $favouriteIds = array_map(
            fn($o) => $o->getId(),
            $user->getFavouriteOrganizations()->toArray()
        );

        return $this->render('client/organizations.html.twig', [
            'organizations' => $organizations,
            'search'        => $search,
            'favourite_ids' => $favouriteIds,
        ]);
    }

    #[Route('/book/{id}', name: 'client_book')]
    public function book(
        int $id,
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        ServiceRepository $svcRepo,
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
        $services  = $svcRepo->findByOrganization($org);
        $error     = null;

        if ($request->isMethod('POST')) {
            $date       = $request->request->get('date', '');
            $time       = $request->request->get('time', '');
            $notes      = trim($request->request->get('notes', ''));
            $employeeId = $request->request->get('employee_id');
            $serviceId  = $request->request->get('service_id');
            $employee   = null;
            $service    = null;

            if ($employeeId) {
                $employee = $empRepo->find((int) $employeeId);
                if (!$employee || $employee->getOrganization() !== $org) {
                    $employee = null;
                }
            }

            if ($serviceId) {
                $service = $svcRepo->find((int) $serviceId);
                if (!$service || $service->getOrganization() !== $org || !$service->isActive()) {
                    $service = null;
                }
            }

            $step           = $org->getTimeStep();
            $effectiveStep  = $service ? $service->getDurationMinutes() : $step;
            $allowedMinutes = [];
            for ($m = 0; $m < 24 * 60; $m += $effectiveStep) {
                $allowedMinutes[] = str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
            }
            $allowedMinutes = array_unique($allowedMinutes);

            $renderForm = fn(string $msg) => $this->render('client/book.html.twig', [
                'organization' => $org,
                'employees'    => $employees,
                'services'     => $services,
                'error'        => $msg,
            ], new \Symfony\Component\HttpFoundation\Response('', 400));

            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $renderForm($t->trans('book.error.date_required'));
            }
            if (!$time || !preg_match('/^\d{2}:\d{2}$/', $time)) {
                return $renderForm($t->trans('book.error.time_required'));
            }
            if (!in_array(substr($time, 3, 2), $allowedMinutes, true)) {
                return $renderForm($t->trans('book.error.time_slot'));
            }

            try {
                $apptDate = new \DateTime($date);
                $apptTime = new \DateTime($time);
            } catch (\Exception) {
                return $renderForm($t->trans('book.error.datetime_invalid'));
            }

            if ($apptDate < new \DateTime('today')) {
                return $renderForm($t->trans('book.error.date_past'));
            }
            if ($employee && $apptRepo->findConflict($employee, $apptDate, $apptTime, null, $service?->getDurationMinutes() ?? $step)) {
                return $renderForm($t->trans('book.error.conflict', ['%name%' => $employee->getName()]));
            }

            $appt = new Appointment();
            $appt->setClient($user)
                 ->setOrganization($org)
                 ->setEmployee($employee)
                 ->setService($service)
                 ->setDurationMinutes($service?->getDurationMinutes())
                 ->setAppointmentDate($apptDate)
                 ->setAppointmentTime($apptTime)
                 ->setNotes($notes ?: null);

            // Auto-confirm if this client is on the org's trusted list
            if ($org->isTrustedClient($user)) {
                $appt->setStatus(Appointment::STATUS_CONFIRMED);
            }

            $em->persist($appt);
            $em->flush();

            $flashKey = $org->isTrustedClient($user)
                ? 'flash.appointment_confirmed'
                : 'flash.appointment_booked';
            $this->addFlash('success', $t->trans($flashKey));
            return $this->redirectToRoute('client_dashboard');
        }

        return $this->render('client/book.html.twig', [
            'organization'  => $org,
            'employees'     => $employees,
            'services'      => $services,
            'error'         => $error,
        ]);
    }

    #[Route('/cancel/{id}', name: 'client_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if ($appt && $appt->getClient() === $user && $appt->getStatus() !== Appointment::STATUS_CANCELLED) {
            $note = trim($request->request->get('cancellation_note', ''));
            $appt->setStatus(Appointment::STATUS_CANCELLED)
                 ->setCancellationNote($note ?: null);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.appointment_cancelled'));

            $redirectTo = $request->request->get('redirect_to', '');
            if ($redirectTo === 'list') {
                return $this->redirectToRoute('client_appointments');
            }
            if ($redirectTo === 'detail') {
                return $this->redirectToRoute('client_appointment_detail', ['id' => $appt->getId()]);
            }
        }

        return $this->redirectToRoute('client_dashboard');
    }

    #[Route('/appointments', name: 'client_appointments')]
    public function appointments(Request $request, AppointmentRepository $repo): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $perPage = 10;
        $page    = max(1, (int) $request->query->get('page', 1));
        $total   = $repo->countByClient($user);
        $pages   = (int) ceil($total / $perPage);
        $page    = min($page, max(1, $pages));

        return $this->render('client/appointments.html.twig', [
            'appointments' => $repo->findByClientPaginated($user, $page, $perPage),
            'page'         => $page,
            'pages'        => $pages,
            'total'        => $total,
        ]);
    }

    #[Route('/appointments/{id}', name: 'client_appointment_detail', requirements: ['id' => '\d+'])]
    public function appointmentDetail(int $id, AppointmentRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if (!$appt || $appt->getClient() !== $user) {
            throw $this->createNotFoundException('Reservation not found.');
        }

        return $this->render('client/appointment_detail.html.twig', [
            'appt' => $appt,
        ]);
    }
}
