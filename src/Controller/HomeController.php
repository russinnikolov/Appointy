<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    #[Route('/contact', name: 'contact')]
    public function contact(Request $request, MailerInterface $mailer, TranslatorInterface $t): Response
    {
        $sent  = false;
        $error = null;

        if ($request->isMethod('POST')) {
            $name    = trim($request->request->get('name', ''));
            $email   = trim($request->request->get('email', ''));
            $message = trim($request->request->get('message', ''));

            if (!$name || !$email || !$message) {
                $error = $t->trans('contact.error_required');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = $t->trans('contact.error_email');
            } else {
                $mail = (new Email())
                    ->from('noreply@grafira.app')
                    ->to('info@grafira.com')
                    ->replyTo($email)
                    ->subject('Grafira contact: ' . $name)
                    ->text(sprintf("Name: %s\nEmail: %s\n\n%s", $name, $email, $message));

                try {
                    $mailer->send($mail);
                    $sent = true;
                } catch (\Throwable) {
                    $error = $t->trans('contact.error_send');
                }
            }
        }

        return $this->render('contact/index.html.twig', [
            'sent'  => $sent,
            'error' => $error,
        ]);
    }

    #[Route('/post-login', name: 'post_login')]
    public function postLogin(): Response
    {
        if ($this->isGranted('ROLE_BUSINESS')) {
            return $this->redirectToRoute('business_dashboard');
        }
        if ($this->isGranted('ROLE_EMPLOYEE')) {
            return $this->redirectToRoute('employee_dashboard');
        }
        return $this->redirectToRoute('client_dashboard');
    }
}
