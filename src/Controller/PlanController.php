<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\PlanCode;
use App\Repository\InvoiceRepository;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/settings/plan')]
class PlanController extends AbstractController
{
    #[Route('', name: 'business_plan')]
    public function index(InvoiceRepository $invoiceRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        return $this->render('business/plan.html.twig', [
            'organization'   => $org,
            'subscription'   => $org->getSubscription(),
            'plans'          => PlanCode::cases(),
            'recentInvoices' => $invoiceRepo->findBy(['organization' => $org], ['periodStart' => 'DESC'], 12),
        ]);
    }

    #[Route('/select/{plan}', name: 'business_plan_select', methods: ['POST'])]
    public function select(
        string $plan,
        StripeService $stripe,
        TranslatorInterface $t,
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org || !$org->getSubscription()) {
            return $this->redirectToRoute('business_dashboard');
        }

        $subscription = $org->getSubscription();

        $planCode = PlanCode::tryFrom($plan);
        if (!$planCode) {
            $this->addFlash('danger', $t->trans('flash.plan_invalid'));
            return $this->redirectToRoute('business_plan');
        }

        if ($planCode === $subscription->getPlan() && $subscription->getStatus() === Subscription::STATUS_ACTIVE) {
            $this->addFlash('danger', $t->trans('flash.plan_already_active'));
            return $this->redirectToRoute('business_plan');
        }

        // During the trial nothing is ever billed, so switching plans is a free, instant local change.
        if ($subscription->isTrialing()) {
            $subscription->setPlan($planCode);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.plan_changed'));
            return $this->redirectToRoute('business_plan');
        }

        // A card is already on file — upgrade/downgrade takes effect immediately, no Stripe round-trip needed.
        // (Existing suspension state, e.g. an unpaid overdue invoice, is left untouched by a plan change.)
        try {
            $hasPaymentMethod = $stripe->hasPaymentMethodOnFile($subscription);
        } catch (\Throwable) {
            $hasPaymentMethod = false; // Stripe unreachable/misconfigured — fall through to the Checkout attempt below
        }

        if ($hasPaymentMethod) {
            $subscription->setPlan($planCode);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.plan_changed'));
            return $this->redirectToRoute('business_plan');
        }

        // No payment method yet — collect one via Checkout before the new plan can be billed.
        $successUrl = $urlGenerator->generate('business_plan', ['checkout' => 'success'], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl  = $urlGenerator->generate('business_plan', ['checkout' => 'cancel'], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $session = $stripe->createSetupSession($subscription, $successUrl, $cancelUrl);
        } catch (\Throwable) {
            $this->addFlash('danger', $t->trans('flash.plan_checkout_failed'));
            return $this->redirectToRoute('business_plan');
        }

        $subscription->setPlan($planCode);
        $em->flush();

        return $this->redirect($session->url);
    }
}
