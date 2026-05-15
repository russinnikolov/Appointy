<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/trusted-clients')]
class BusinessTrustedClientController extends AbstractController
{
    private function getOrg(): \App\Entity\Organization
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            throw $this->createNotFoundException('No organization linked to this account.');
        }

        return $org;
    }

    #[Route('', name: 'business_trusted_clients')]
    public function index(AppointmentRepository $apptRepo): Response
    {
        $org     = $this->getOrg();
        $clients = $apptRepo->findDistinctClientsByOrg($org);

        // Build a quick lookup: userId → booking count
        $bookingCounts = [];
        foreach ($clients as $client) {
            $bookingCounts[$client->getId()] = $apptRepo->count([
                'organization' => $org,
                'client'       => $client,
            ]);
        }

        return $this->render('business/trusted_clients.html.twig', [
            'organization'  => $org,
            'clients'       => $clients,
            'booking_counts' => $bookingCounts,
        ]);
    }

    #[Route('/{id}/toggle', name: 'business_trusted_client_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, UserRepository $userRepo, EntityManagerInterface $em): JsonResponse
    {
        $org    = $this->getOrg();
        $client = $userRepo->find($id);

        if (!$client || !$client->isClient()) {
            return $this->json(['error' => 'Client not found'], 404);
        }

        if ($org->isTrustedClient($client)) {
            $org->removeTrustedClient($client);
            $trusted = false;
        } else {
            $org->addTrustedClient($client);
            $trusted = true;
        }

        $em->flush();

        return $this->json(['trusted' => $trusted]);
    }
}
