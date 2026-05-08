<?php

namespace App\Controller;

use App\Entity\BlockedPeriod;
use App\Entity\User;
use App\Repository\BlockedPeriodRepository;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/blocked-times')]
class BlockedPeriodController extends AbstractController
{
    #[Route('', name: 'business_blocked_times')]
    public function index(
        Request $request,
        BlockedPeriodRepository $repo,
        EmployeeRepository $empRepo,
        EntityManagerInterface $em,
        TranslatorInterface $t
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $employees = $empRepo->findBy(['organization' => $org, 'isActive' => true], ['name' => 'ASC']);
        $error     = null;

        if ($request->isMethod('POST')) {
            $date      = $request->request->get('date', '');
            $startTime = $request->request->get('start_time', '');
            $endTime   = $request->request->get('end_time', '');
            $label     = trim($request->request->get('label', ''));
            $empId     = $request->request->get('employee_id');

            if (!$date || !$startTime || !$endTime) {
                $error = 'Please fill in date, start time and end time.';
            } elseif (new \DateTime($startTime) >= new \DateTime($endTime)) {
                $error = 'End time must be after start time.';
            } else {
                $employee = $empId ? $empRepo->find((int) $empId) : null;
                if ($employee && $employee->getOrganization() !== $org) {
                    $employee = null;
                }

                $block = new BlockedPeriod();
                $block->setOrganization($org)
                      ->setEmployee($employee)
                      ->setDate(new \DateTime($date))
                      ->setStartTime(new \DateTime($startTime))
                      ->setEndTime(new \DateTime($endTime))
                      ->setLabel($label ?: null);

                $em->persist($block);
                $em->flush();

                $this->addFlash('success', $t->trans('flash.blocked_added'));
                return $this->redirectToRoute('business_blocked_times');
            }
        }

        return $this->render('business/blocked_times.html.twig', [
            'organization' => $org,
            'blocks'       => $repo->findByOrganizationOrdered($org),
            'employees'    => $employees,
            'error'        => $error,
        ]);
    }

    #[Route('/{id}/delete', name: 'business_blocked_delete', methods: ['POST'])]
    public function delete(int $id, BlockedPeriodRepository $repo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $block = $repo->find($id);

        if ($block && $block->getOrganization() === $user->getOrganization()) {
            $em->remove($block);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.blocked_removed'));
        }

        return $this->redirectToRoute('business_blocked_times');
    }
}
