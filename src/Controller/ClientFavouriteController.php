<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/client/favourites')]
class ClientFavouriteController extends AbstractController
{
    #[Route('', name: 'client_favourites')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('client/favourites.html.twig', [
            'organizations' => $user->getFavouriteOrganizations()->toArray(),
        ]);
    }

    #[Route('/{id}/toggle', name: 'client_favourite_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, OrganizationRepository $orgRepo, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $orgRepo->find($id);

        if (!$org) {
            return $this->json(['error' => 'Not found'], 404);
        }

        if ($user->isFavouriteOrganization($org)) {
            $user->removeFavouriteOrganization($org);
            $favourited = false;
        } else {
            $user->addFavouriteOrganization($org);
            $favourited = true;
        }

        $em->flush();

        return $this->json(['favourited' => $favourited]);
    }
}
