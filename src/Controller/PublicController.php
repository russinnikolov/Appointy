<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Util\PhoneValidator;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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

    #[Route('/{orgId}/book/{employeeId}', name: 'public_book', requirements: ['orgId' => '\d+', 'employeeId' => '\d+'])]
    public function book(
        int $orgId,
        int $employeeId,
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        EntityManagerInterface $em
    ): Response {
        $org      = $orgRepo->find($orgId);
        $employee = $empRepo->find($employeeId);

        if (!$org || !$employee || $employee->getOrganization() !== $org) {
            throw $this->createNotFoundException('Organization or employee not found.');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $guestName  = trim($request->request->get('guest_name', ''));
            $guestPhone = trim($request->request->get('guest_phone', ''));
            $date       = $request->request->get('date', '');
            $time       = $request->request->get('time', '');
            $notes      = trim($request->request->get('notes', ''));

            $step  = $org->getTimeStep();
            $allowedMinutes = [];
            for ($m = 0; $m < 60; $m += $step) {
                $allowedMinutes[] = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            }

            if (!$guestName || !$guestPhone) {
                $error = 'Please provide your name and phone number.';
            } elseif (!PhoneValidator::isValid($guestPhone)) {
                $error = 'Please enter a valid phone number.';
            } elseif (!$date || !$time) {
                $error = 'Please select a date and time.';
            } elseif (!in_array(substr($time, 3, 2), $allowedMinutes, true)) {
                $error = 'Please select a valid time slot.';
            } elseif (new \DateTime($date) < new \DateTime('today')) {
                $error = 'Appointment date cannot be in the past.';
            } elseif ($apptRepo->findConflict($employee, new \DateTime($date), new \DateTime($time), null, $org->getTimeStep())) {
                $error = $employee->getName() . ' is already booked at that time. Please choose a different time.';
            } else {
                $appt = new Appointment();
                $appt->setOrganization($org)
                     ->setEmployee($employee)
                     ->setGuestName($guestName)
                     ->setGuestPhone($guestPhone)
                     ->setAppointmentDate(new \DateTime($date))
                     ->setAppointmentTime(new \DateTime($time))
                     ->setNotes($notes ?: null);

                $em->persist($appt);
                $em->flush();

                return $this->render('public/booking_confirmed.html.twig', [
                    'appointment'  => $appt,
                    'organization' => $org,
                    'employee'     => $employee,
                ]);
            }
        }

        return $this->render('public/book.html.twig', [
            'organization' => $org,
            'employee'     => $employee,
            'error'        => $error,
        ]);
    }
}
