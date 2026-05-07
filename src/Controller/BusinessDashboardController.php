<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business')]
class BusinessDashboardController extends AbstractController
{
    #[Route('', name: 'business_dashboard')]
    public function index(AppointmentRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            $this->addFlash('danger', 'Your account has no linked organization.');
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
            'notes'             => $a->getNotes(),
            'cancel_note'       => $a->getCancellationNote(),
        ], $appointments);

        return $this->render('business/dashboard.html.twig', [
            'organization'      => $org,
            'appointments_json' => json_encode($apptData),
            'counts'            => $repo->countByStatus($org),
        ]);
    }

    #[Route('/confirm/{id}', name: 'business_confirm', methods: ['POST'])]
    public function confirm(int $id, AppointmentRepository $repo, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if ($appt && $appt->getOrganization() === $user->getOrganization()
            && $appt->getStatus() === Appointment::STATUS_PENDING) {
            $appt->setStatus(Appointment::STATUS_CONFIRMED);
            $em->flush();
            $this->addFlash('success', 'Appointment confirmed.');
        }

        return $this->redirectToRoute('business_dashboard');
    }

    #[Route('/cancel/{id}', name: 'business_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if ($appt && $appt->getOrganization() === $user->getOrganization()
            && $appt->getStatus() !== Appointment::STATUS_CANCELLED) {
            $note = trim($request->request->get('cancellation_note', ''));
            $appt->setStatus(Appointment::STATUS_CANCELLED)
                 ->setCancellationNote($note ?: null);
            $em->flush();
            $this->addFlash('success', 'Appointment cancelled.');
        }

        return $this->redirectToRoute('business_dashboard');
    }
}
