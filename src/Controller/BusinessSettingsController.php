<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Service\TranslationApiService;
use App\Util\PhoneValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/settings')]
class BusinessSettingsController extends AbstractController
{
    #[Route('/hours', name: 'business_working_hours')]
    public function workingHours(Request $request, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        if ($request->isMethod('POST')) {
            $nonstop = (bool) $request->request->get('nonstop');
            $org->setNonstop($nonstop);

            $hours = [];
            foreach (Organization::DAYS as $day) {
                $hours[$day] = [
                    'enabled' => $nonstop ? true : (bool) $request->request->get("enabled_{$day}"),
                    'open'    => $nonstop ? '00:00' : $request->request->get("open_{$day}", '09:00'),
                    'close'   => $nonstop ? '23:59' : $request->request->get("close_{$day}", '18:00'),
                ];
            }
            $step = (int) $request->request->get('time_step', 15);
            if (!in_array($step, [15, 30, 60, 120], true)) {
                $step = 15;
            }
            $org->setWorkingHours($hours)->setTimeStep($step);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.hours_updated'));
            return $this->redirectToRoute('business_working_hours');
        }

        return $this->render('business/working_hours.html.twig', [
            'organization' => $org,
            'hours'        => $org->getWorkingHours(),
        ]);
    }

    #[Route('/info', name: 'business_settings_info')]
    public function info(Request $request, EntityManagerInterface $em, TranslatorInterface $t, TranslationApiService $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $name  = trim($request->request->get('name', ''));
            $phone = trim($request->request->get('phone', ''));
            if (!$name) {
                $error = $t->trans('flash.name_required');
            } elseif ($phone && !PhoneValidator::isValid($phone)) {
                $error = 'Please enter a valid phone number.';
            } else {
            $org->setName($name)
                ->setAddress(trim($request->request->get('address', '')) ?: null)
                ->setCity(trim($request->request->get('city', '')) ?: null)
                ->setCountry(trim($request->request->get('country', '')) ?: null)
                ->setZipCode(trim($request->request->get('zip_code', '')) ?: null)
                ->setPhone($phone ?: null)
                ->setEmail(trim($request->request->get('email', '')) ?: null)
                ->setDescription(trim($request->request->get('description', '')) ?: null);

            $lat = $request->request->get('latitude', '');
            $lng = $request->request->get('longitude', '');
            $org->setLatitude($lat !== '' ? (float) $lat : null)
                ->setLongitude($lng !== '' ? (float) $lng : null);

            $logoFile = $request->files->get('logo');
            if ($logoFile && $logoFile->isValid()) {
                $ext = strtolower($logoFile->guessExtension() ?? $logoFile->getClientOriginalExtension() ?? 'jpg');
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/logos/';
                    $filename   = 'org-' . $org->getId() . '-' . uniqid() . '.' . $ext;
                    if ($org->getLogoFilename()) {
                        @unlink($uploadsDir . $org->getLogoFilename());
                    }
                    $logoFile->move($uploadsDir, $filename);
                    $org->setLogoFilename($filename);
                }
            }

            $translator->translateOrg($org, $request->getLocale());
            $em->flush();
            $this->addFlash('success', $t->trans('flash.info_updated'));
            return $this->redirectToRoute('business_settings_info');
            } // end phone validation else
        }

        return $this->render('business/settings_info.html.twig', [
            'organization' => $org,
            'error'        => $error,
        ]);
    }
}
