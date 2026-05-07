<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        return $this->render('business/dashboard.html.twig', [
            'organization' => $org,
            'appointments' => $repo->findByOrganization($org),
            'counts'       => $repo->countByStatus($org),
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
    public function cancel(int $id, AppointmentRepository $repo, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if ($appt && $appt->getOrganization() === $user->getOrganization()
            && $appt->getStatus() !== Appointment::STATUS_CANCELLED) {
            $appt->setStatus(Appointment::STATUS_CANCELLED);
            $em->flush();
            $this->addFlash('success', 'Appointment cancelled.');
        }

        return $this->redirectToRoute('business_dashboard');
    }
}
