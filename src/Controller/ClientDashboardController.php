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

#[IsGranted('ROLE_CLIENT')]
#[Route('/client')]
class ClientDashboardController extends AbstractController
{
    #[Route('', name: 'client_dashboard')]
    public function index(AppointmentRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('client/dashboard.html.twig', [
            'appointments' => $repo->findByClient($user),
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
        EntityManagerInterface $em
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

            if (!$date || !$time) {
                $error = 'Please select both a date and a time.';
            } elseif (!in_array(substr($time, 3, 2), ['00', '15', '30', '45'], true)) {
                $error = 'Please select a valid time (on :00, :15, :30 or :45).';
            } elseif (new \DateTime($date) < new \DateTime('today')) {
                $error = 'Appointment date cannot be in the past.';
            } else {
                $appt = new Appointment();
                $appt->setClient($user)
                     ->setOrganization($org)
                     ->setAppointmentDate(new \DateTime($date))
                     ->setAppointmentTime(new \DateTime($time))
                     ->setNotes($notes ?: null);

                if ($employeeId) {
                    $employee = $empRepo->find((int) $employeeId);
                    if ($employee && $employee->getOrganization() === $org) {
                        $appt->setEmployee($employee);
                    }
                }

                $em->persist($appt);
                $em->flush();

                $this->addFlash('success', 'Appointment booked! Waiting for confirmation.');
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
    public function cancel(int $id, AppointmentRepository $repo, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if ($appt && $appt->getClient() === $user && $appt->getStatus() === Appointment::STATUS_PENDING) {
            $appt->setStatus(Appointment::STATUS_CANCELLED);
            $em->flush();
            $this->addFlash('success', 'Appointment cancelled.');
        }

        return $this->redirectToRoute('client_dashboard');
    }
}
