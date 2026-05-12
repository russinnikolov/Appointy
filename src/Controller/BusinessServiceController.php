<?php

namespace App\Controller;

use App\Entity\Service;
use App\Entity\User;
use App\Repository\EmployeeRepository;
use App\Repository\ServiceRepository;
use App\Service\TranslationApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/services')]
class BusinessServiceController extends AbstractController
{
    #[Route('', name: 'business_services')]
    public function index(ServiceRepository $repo, EmployeeRepository $empRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $services  = $repo->findByOrganization($org);
        $employees = $empRepo->findBy(['organization' => $org, 'isActive' => true], ['name' => 'ASC']);

        return $this->render('business/services.html.twig', [
            'organization' => $org,
            'services'     => $services,
            'employees'    => $employees,
        ]);
    }

    #[Route('/create', name: 'business_service_create', methods: ['POST'])]
    public function create(Request $request, EmployeeRepository $empRepo, EntityManagerInterface $em, TranslatorInterface $t, TranslationApiService $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $service = new Service();
        $service->setOrganization($org);

        $this->hydrateFromRequest($service, $request, $empRepo, $org);
        $translator->translateService($service, $request->getLocale());

        $em->persist($service);
        $em->flush();

        $this->addFlash('success', $t->trans('flash.service_created', ['%name%' => $service->getName()]));
        return $this->redirectToRoute('business_services');
    }

    #[Route('/{id}/update', name: 'business_service_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request, ServiceRepository $repo, EmployeeRepository $empRepo, EntityManagerInterface $em, TranslatorInterface $t, TranslationApiService $translator): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $service = $repo->find($id);

        if (!$service || $service->getOrganization() !== $user->getOrganization()) {
            throw $this->createNotFoundException();
        }

        $this->hydrateFromRequest($service, $request, $empRepo, $user->getOrganization());
        $translator->translateService($service, $request->getLocale());
        $em->flush();

        $this->addFlash('success', $t->trans('flash.service_updated', ['%name%' => $service->getName()]));
        return $this->redirectToRoute('business_services');
    }

    #[Route('/{id}/toggle', name: 'business_service_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, ServiceRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $service = $repo->find($id);

        if (!$service || $service->getOrganization() !== $user->getOrganization()) {
            return new JsonResponse(['ok' => false], 403);
        }

        $service->setIsActive(!$service->isActive());
        $em->flush();

        return new JsonResponse(['ok' => true, 'active' => $service->isActive()]);
    }

    #[Route('/{id}/delete', name: 'business_service_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, ServiceRepository $repo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $service = $repo->find($id);

        if ($service && $service->getOrganization() === $user->getOrganization()) {
            $em->remove($service);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.service_deleted'));
        }

        return $this->redirectToRoute('business_services');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function hydrateFromRequest(Service $service, Request $req, EmployeeRepository $empRepo, \App\Entity\Organization $org): void
    {
        $service->setName(trim($req->request->get('name', '')));
        $service->setDescription(trim($req->request->get('description', '')) ?: null);
        $service->setDurationMinutes(max(1, (int) $req->request->get('duration_minutes', 30)));
        $price = trim($req->request->get('price', ''));
        $service->setPrice($price !== '' ? $price : null);

        // Re-sync employee assignments
        foreach ($service->getEmployees() as $existing) {
            $service->removeEmployee($existing);
        }

        foreach ($req->request->all('employee_ids') as $empId) {
            $emp = $empRepo->find((int) $empId);
            if ($emp && $emp->getOrganization() === $org) {
                $service->addEmployee($emp);
            }
        }
    }
}
