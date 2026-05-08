<?php

namespace App\Controller;

use App\Entity\User;
use App\Util\PhoneValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/profile', name: 'business_profile')]
class BusinessProfileController extends AbstractController
{
    #[Route('', name: '')]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        TranslatorInterface $t
    ): Response {
        /** @var User $user */
        $user  = $this->getUser();
        $org   = $user->getOrganization();
        $error = null;

        if ($request->isMethod('POST')) {
            $name     = trim($request->request->get('name', ''));
            $phone    = trim($request->request->get('phone', ''));
            $newPass  = $request->request->get('new_password', '');
            $confPass = $request->request->get('confirm_password', '');

            if (!$name) {
                $error = 'Name is required.';
            } elseif ($phone && !PhoneValidator::isValid($phone)) {
                $error = 'Please enter a valid phone number.';
            } elseif ($newPass && strlen($newPass) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($newPass && $newPass !== $confPass) {
                $error = 'Passwords do not match.';
            } else {
                $user->setName($name)->setPhone($phone ?: null);

                if ($newPass) {
                    $user->setPassword($hasher->hashPassword($user, $newPass));
                }

                $avatarFile = $request->files->get('avatar');
                if ($avatarFile && $avatarFile->isValid()) {
                    $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars/';
                    $ext        = $avatarFile->getClientOriginalExtension() ?: 'jpg';
                    $filename   = 'user-' . $user->getId() . '-' . uniqid() . '.' . $ext;
                    if ($user->getAvatarFilename()) {
                        @unlink($uploadsDir . $user->getAvatarFilename());
                    }
                    $avatarFile->move($uploadsDir, $filename);
                    $user->setAvatarFilename($filename);
                }

                if ($org) {
                    $logoFile = $request->files->get('logo');
                    if ($logoFile && $logoFile->isValid()) {
                        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/logos/';
                        $ext        = $logoFile->getClientOriginalExtension() ?: 'jpg';
                        $filename   = 'org-' . $org->getId() . '-' . uniqid() . '.' . $ext;
                        if ($org->getLogoFilename()) {
                            @unlink($uploadsDir . $org->getLogoFilename());
                        }
                        $logoFile->move($uploadsDir, $filename);
                        $org->setLogoFilename($filename);
                    }
                }

                $em->flush();
                $this->addFlash('success', $t->trans('flash.profile_updated'));
                return $this->redirectToRoute('business_profile');
            }
        }

        return $this->render('business/profile.html.twig', [
            'organization' => $org,
            'error'        => $error,
        ]);
    }
}
