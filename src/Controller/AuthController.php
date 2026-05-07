<?php

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('post_login');
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'logout')]
    public function logout(): void {}

    #[Route('/register', name: 'register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        UserRepository $userRepo
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('post_login');
        }

        $type  = $request->query->get('type', User::TYPE_CLIENT);
        $error = null;

        if ($request->isMethod('POST')) {
            $type  = $request->request->get('type', User::TYPE_CLIENT);
            $name  = trim($request->request->get('name', ''));
            $email = trim($request->request->get('email', ''));
            $pass  = $request->request->get('password', '');
            $conf  = $request->request->get('confirm_password', '');
            $phone = trim($request->request->get('phone', ''));

            if (!$name || !$email || !$pass) {
                $error = 'Please fill in all required fields.';
            } elseif ($pass !== $conf) {
                $error = 'Passwords do not match.';
            } elseif (strlen($pass) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($userRepo->findOneBy(['email' => $email])) {
                $error = 'This email is already registered.';
            } else {
                $user = new User();
                $user->setName($name)
                     ->setEmail($email)
                     ->setPhone($phone ?: null)
                     ->setType($type)
                     ->setPassword($hasher->hashPassword($user, $pass));

                if ($type === User::TYPE_BUSINESS) {
                    $orgName    = trim($request->request->get('org_name', ''));
                    $orgAddress = trim($request->request->get('org_address', ''));
                    $orgCat     = trim($request->request->get('org_category', ''));
                    $orgDesc    = trim($request->request->get('org_description', ''));
                    $orgEmail   = trim($request->request->get('org_email', ''));
                    $orgPhone   = trim($request->request->get('org_phone', ''));

                    if (!$orgName) {
                        $error = 'Please enter your organization name.';
                        goto render;
                    }

                    $orgCity    = trim($request->request->get('org_city', ''));
                    $orgCountry = trim($request->request->get('org_country', ''));
                    $orgZip     = trim($request->request->get('org_zip', ''));

                    $org = new Organization();
                    $org->setName($orgName)
                        ->setEmail($orgEmail ?: null)
                        ->setPhone($orgPhone ?: null)
                        ->setAddress($orgAddress ?: null)
                        ->setCity($orgCity ?: null)
                        ->setCountry($orgCountry ?: null)
                        ->setZipCode($orgZip ?: null)
                        ->setCategory($orgCat ?: null)
                        ->setDescription($orgDesc ?: null);

                    $em->persist($org);
                    $user->setOrganization($org);
                }

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Account created! You can now log in.');
                return $this->redirectToRoute('login');
            }
        }

        render:
        return $this->render('auth/register.html.twig', [
            'error' => $error,
            'type'  => $type,
        ]);
    }
}
