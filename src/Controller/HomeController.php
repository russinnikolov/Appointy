<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        if ($this->isGranted('ROLE_BUSINESS')) {
            return $this->redirectToRoute('business_dashboard');
        }
        if ($this->isGranted('ROLE_CLIENT')) {
            return $this->redirectToRoute('client_dashboard');
        }

        return $this->render('home/index.html.twig');
    }

    #[Route('/post-login', name: 'post_login')]
    public function postLogin(): Response
    {
        if ($this->isGranted('ROLE_BUSINESS')) {
            return $this->redirectToRoute('business_dashboard');
        }
        return $this->redirectToRoute('client_dashboard');
    }
}
