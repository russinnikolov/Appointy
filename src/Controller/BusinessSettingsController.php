<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/settings')]
class BusinessSettingsController extends AbstractController
{
    #[Route('/hours', name: 'business_working_hours')]
    public function workingHours(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        if ($request->isMethod('POST')) {
            $hours = [];
            foreach (Organization::DAYS as $day) {
                $hours[$day] = [
                    'enabled' => (bool) $request->request->get("enabled_{$day}"),
                    'open'    => $request->request->get("open_{$day}", '09:00'),
                    'close'   => $request->request->get("close_{$day}", '18:00'),
                ];
            }
            $step = (int) $request->request->get('time_step', 15);
            if (!in_array($step, [15, 30, 60, 120], true)) {
                $step = 15;
            }
            $org->setWorkingHours($hours)->setTimeStep($step);
            $em->flush();
            $this->addFlash('success', 'Working hours updated.');
            return $this->redirectToRoute('business_working_hours');
        }

        return $this->render('business/working_hours.html.twig', [
            'organization' => $org,
            'hours'        => $org->getWorkingHours(),
        ]);
    }

    #[Route('/info', name: 'business_settings_info')]
    public function info(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        if ($request->isMethod('POST')) {
            $org->setAddress(trim($request->request->get('address', '')) ?: null)
                ->setCity(trim($request->request->get('city', '')) ?: null)
                ->setCountry(trim($request->request->get('country', '')) ?: null)
                ->setZipCode(trim($request->request->get('zip_code', '')) ?: null)
                ->setPhone(trim($request->request->get('phone', '')) ?: null)
                ->setEmail(trim($request->request->get('email', '')) ?: null)
                ->setDescription(trim($request->request->get('description', '')) ?: null);

            $lat = $request->request->get('latitude', '');
            $lng = $request->request->get('longitude', '');
            $org->setLatitude($lat !== '' ? (float) $lat : null)
                ->setLongitude($lng !== '' ? (float) $lng : null);

            $em->flush();
            $this->addFlash('success', 'Organization info updated.');
            return $this->redirectToRoute('business_settings_info');
        }

        return $this->render('business/settings_info.html.twig', [
            'organization' => $org,
        ]);
    }
}
