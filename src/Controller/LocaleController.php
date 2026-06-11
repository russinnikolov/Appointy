<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'set_locale', requirements: ['locale' => 'bg|en'])]
    public function switchLocale(string $locale, Request $request, EntityManagerInterface $em): Response
    {
        $request->getSession()->set('_locale', $locale);

        // Persist preference so it survives across sessions / devices
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user instanceof User) {
            $user->setLocale($locale);
            $em->flush();
        }

        $referer = $request->headers->get('Referer', '');
        $origin  = $request->getSchemeAndHttpHost();
        if (!$referer || !str_starts_with($referer, $origin)) {
            $referer = $this->generateUrl('home');
        }
        return $this->redirect($referer);
    }
}
