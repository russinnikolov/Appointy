<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\Service;
use App\Util\PhoneValidator;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\OrganizationRepository;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/orgs')]
class PublicController extends AbstractController
{
    #[Route('', name: 'public_organizations')]
    public function list(Request $request, OrganizationRepository $repo): Response
    {
        $search = trim($request->query->get('q', ''));

        $organizations = $search
            ? $repo->search($search)
            : $repo->findBy([], ['name' => 'ASC']);

        return $this->render('public/organizations.html.twig', [
            'organizations' => $organizations,
            'search'        => $search,
        ]);
    }

    #[Route('/{id}', name: 'public_organization', requirements: ['id' => '\d+'])]
    public function show(int $id, OrganizationRepository $orgRepo, EmployeeRepository $empRepo): Response
    {
        $org = $orgRepo->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found.');
        }

        return $this->render('public/organization.html.twig', [
            'organization' => $org,
            'employees'    => $empRepo->findActiveByOrganization($org),
        ]);
    }

    /**
     * Book with any / no pre-selected employee.
     * Logged-in clients are forwarded to the authenticated booking flow.
     */
    #[Route('/{id}/book', name: 'public_book_org', requirements: ['id' => '\d+'])]
    public function bookOrg(
        int $id,
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        ServiceRepository $svcRepo,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        // Logged-in clients use their own richer booking flow
        if ($this->isGranted('ROLE_CLIENT')) {
            return $this->redirectToRoute('client_book', ['id' => $id]);
        }

        $org = $orgRepo->find($id);
        if (!$org) {
            throw $this->createNotFoundException('Organization not found.');
        }

        $employees   = $empRepo->findActiveByOrganization($org);
        $allServices = $svcRepo->findByOrganization($org);
        $error       = null;

        if ($request->isMethod('POST')) {
            $guestName  = trim($request->request->get('guest_name', ''));
            $guestPhone = trim($request->request->get('guest_phone', ''));
            $date       = $request->request->get('date', '');
            $time       = $request->request->get('time', '');
            $notes      = trim($request->request->get('notes', ''));
            $employeeId = $request->request->get('employee_id');
            $serviceId  = $request->request->get('service_id');

            $employee = null;
            $service  = null;

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
            $allowedMinutes = [];
            for ($m = 0; $m < 60; $m += $step) {
                $allowedMinutes[] = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            }

            $renderForm = fn(string $msg) => $this->render('public/book_org.html.twig', [
                'organization' => $org,
                'employees'    => $employees,
                'services'     => $allServices,
                'error'        => $msg,
            ], new Response('', 400));

            if (!$guestName) {
                return $renderForm($t->trans('book.error.name_required'));
            }
            if (!$guestPhone) {
                return $renderForm($t->trans('book.error.phone_required'));
            }
            if (!PhoneValidator::isValid($guestPhone)) {
                return $renderForm($t->trans('book.error.phone_invalid'));
            }
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
            $appt->setOrganization($org)
                 ->setEmployee($employee)
                 ->setService($service)
                 ->setDurationMinutes($service?->getDurationMinutes())
                 ->setGuestName($guestName)
                 ->setGuestPhone($guestPhone)
                 ->setAppointmentDate($apptDate)
                 ->setAppointmentTime($apptTime)
                 ->setNotes($notes ?: null);

            $em->persist($appt);
            $em->flush();

            return $this->render('public/booking_confirmed.html.twig', [
                'appointment'  => $appt,
                'organization' => $org,
                'employee'     => $employee,
            ]);
        }

        return $this->render('public/book_org.html.twig', [
            'organization' => $org,
            'employees'    => $employees,
            'services'     => $allServices,
            'error'        => $error,
        ]);
    }

    /** Returns the active services visible for a given employee choice (null = any). */
    private function relevantServices(array $allServices, ?\App\Entity\Employee $employee): array
    {
        return array_values(array_filter($allServices, function (Service $s) use ($employee) {
            if (!$s->isActive()) return false;
            if ($employee === null) return $s->isForAllEmployees();
            return $s->isForAllEmployees() || $s->getEmployees()->contains($employee);
        }));
    }

    #[Route('/{orgId}/book/{employeeId}', name: 'public_book', requirements: ['orgId' => '\d+', 'employeeId' => '\d+'])]
    public function book(
        int $orgId,
        int $employeeId,
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        ServiceRepository $svcRepo,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        $org      = $orgRepo->find($orgId);
        $employee = $empRepo->find($employeeId);

        if (!$org || !$employee || $employee->getOrganization() !== $org) {
            throw $this->createNotFoundException('Organization or employee not found.');
        }

        $services = $svcRepo->findActiveForEmployee($employee);
        $error    = null;

        if ($request->isMethod('POST')) {
            $guestName  = trim($request->request->get('guest_name', ''));
            $guestPhone = trim($request->request->get('guest_phone', ''));
            $date       = $request->request->get('date', '');
            $time       = $request->request->get('time', '');
            $notes      = trim($request->request->get('notes', ''));
            $serviceId  = $request->request->get('service_id');
            $service    = null;

            if ($serviceId) {
                $service = $svcRepo->find((int) $serviceId);
                if (!$service || $service->getOrganization() !== $org || !$service->isActive()) {
                    $service = null;
                }
            }

            $step  = $org->getTimeStep();
            $allowedMinutes = [];
            for ($m = 0; $m < 60; $m += $step) {
                $allowedMinutes[] = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            }

            $renderForm = fn(string $msg) => $this->render('public/book.html.twig', [
                'organization' => $org,
                'employee'     => $employee,
                'services'     => $services,
                'error'        => $msg,
            ], new Response('', 400));

            if (!$guestName) {
                return $renderForm($t->trans('book.error.name_required'));
            }
            if (!$guestPhone) {
                return $renderForm($t->trans('book.error.phone_required'));
            }
            if (!PhoneValidator::isValid($guestPhone)) {
                return $renderForm($t->trans('book.error.phone_invalid'));
            }
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
            if ($apptRepo->findConflict($employee, $apptDate, $apptTime, null, $service?->getDurationMinutes() ?? $step)) {
                return $renderForm($t->trans('book.error.conflict', ['%name%' => $employee->getName()]));
            }

            $appt = new Appointment();
            $appt->setOrganization($org)
                 ->setEmployee($employee)
                 ->setService($service)
                 ->setDurationMinutes($service?->getDurationMinutes())
                 ->setGuestName($guestName)
                 ->setGuestPhone($guestPhone)
                 ->setAppointmentDate($apptDate)
                 ->setAppointmentTime($apptTime)
                 ->setNotes($notes ?: null);

            $em->persist($appt);
            $em->flush();

            return $this->render('public/booking_confirmed.html.twig', [
                'appointment'  => $appt,
                'organization' => $org,
                'employee'     => $employee,
            ]);
        }

        return $this->render('public/book.html.twig', [
            'organization' => $org,
            'employee'     => $employee,
            'services'     => $services,
            'error'        => $error,
        ]);
    }
}
