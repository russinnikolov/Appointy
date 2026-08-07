<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Enum\PlanCode;
use App\Repository\InvoiceRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\StripeService;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookController extends AbstractController
{
    #[Route('/webhooks/stripe', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        StripeService $stripe,
        InvoiceRepository $invoiceRepo,
        SubscriptionRepository $subRepo,
        EntityManagerInterface $em,
    ): Response {
        try {
            $event = $stripe->verifyWebhookSignature($request->getContent(), $request->headers->get('Stripe-Signature', ''));
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return new Response('', 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event, $subRepo, $em),
            'invoice.payment_succeeded', 'payment_intent.succeeded' => $this->handlePaymentSucceeded($event, $invoiceRepo, $em),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event, $subRepo, $em),
            default => null,
        };

        return new Response('', 200);
    }

    private function handleCheckoutCompleted($event, SubscriptionRepository $subRepo, EntityManagerInterface $em): void
    {
        $session     = $event->data->object;
        $customerId  = $session->customer;
        $subscription = $subRepo->createQueryBuilder('s')
            ->andWhere('s.stripeCustomerId = :cid')
            ->setParameter('cid', $customerId)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$subscription) {
            return;
        }

        if ($session->subscription) {
            $subscription->setStripeSubscriptionId($session->subscription);
        }
        if ($subscription->getStatus() !== Subscription::STATUS_TRIALING) {
            $subscription->setStatus(Subscription::STATUS_ACTIVE);
        }

        $em->flush();
    }

    private function handlePaymentSucceeded($event, InvoiceRepository $invoiceRepo, EntityManagerInterface $em): void
    {
        $object = $event->data->object;

        $invoice = null;
        if ($event->type === 'payment_intent.succeeded') {
            $invoice = $invoiceRepo->findOneBy(['stripePaymentIntentId' => $object->id]);
        } else {
            $invoice = $invoiceRepo->findOneBy(['stripeInvoiceId' => $object->id]);
        }

        if (!$invoice) {
            return;
        }

        $invoice->setStatus(Invoice::STATUS_PAID)->setPaidAt(new \DateTimeImmutable());

        $subscription = $invoice->getSubscription();
        if ($subscription->getStatus() === Subscription::STATUS_PAST_DUE) {
            $subscription->setStatus(Subscription::STATUS_ACTIVE);
        }

        $em->flush();
    }

    private function handleSubscriptionDeleted($event, SubscriptionRepository $subRepo, EntityManagerInterface $em): void
    {
        $stripeSubId  = $event->data->object->id;
        $subscription = $subRepo->findOneBy(['stripeSubscriptionId' => $stripeSubId]);

        if (!$subscription) {
            return;
        }

        $subscription->setStatus(Subscription::STATUS_CANCELED);
        $em->flush();
    }
}
