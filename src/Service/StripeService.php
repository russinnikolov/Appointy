<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Enum\PlanCode;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe SDK — the single point of contact with Stripe's API.
 * Stripe Price IDs for the 3 flat-fee plans are read from env vars since there is no
 * admin UI to manage them; the pay-per-reservation plan has no fixed Price (variable amount).
 */
class StripeService
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $webhookSecret,
    ) {
    }

    public function createCustomer(Organization $org): string
    {
        $customer = $this->stripe->customers->create([
            'name'  => $org->getName(),
            'email' => $org->getEmail(),
            'metadata' => ['organization_id' => (string) $org->getId()],
        ]);

        return $customer->id;
    }

    private function ensureCustomer(Subscription $subscription): string
    {
        if ($subscription->getStripeCustomerId()) {
            return $subscription->getStripeCustomerId();
        }

        $customerId = $this->createCustomer($subscription->getOrganization());
        $subscription->setStripeCustomerId($customerId);

        return $customerId;
    }

    public function createCheckoutSession(Subscription $subscription, PlanCode $plan, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $customerId = $this->ensureCustomer($subscription);

        if ($plan === PlanCode::PAY_PER_RESERVATION) {
            // No fixed recurring price for this plan — just save a card for later off-session charges.
            return $this->stripe->checkout->sessions->create([
                'mode'        => 'setup',
                'customer'    => $customerId,
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
            ]);
        }

        $priceId = $this->priceIdForPlan($plan);

        return $this->stripe->checkout->sessions->create([
            'mode'        => 'subscription',
            'customer'    => $customerId,
            'line_items'  => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ]);
    }

    private function priceIdForPlan(PlanCode $plan): string
    {
        $envVar = match ($plan) {
            PlanCode::SMALL_BUSINESS  => 'STRIPE_PRICE_SMALL_BUSINESS',
            PlanCode::MEDIUM_BUSINESS => 'STRIPE_PRICE_MEDIUM_BUSINESS',
            PlanCode::ENTERPRISE      => 'STRIPE_PRICE_ENTERPRISE',
            default => throw new \InvalidArgumentException('Plan has no fixed Stripe price: ' . $plan->value),
        };

        $priceId = $_ENV[$envVar] ?? getenv($envVar);
        if (!$priceId) {
            throw new \RuntimeException("Missing Stripe price ID env var: {$envVar}");
        }

        return $priceId;
    }

    /** Off-session charge for a variable-amount invoice (pay-per-reservation, enterprise overage). */
    public function chargeInvoice(Invoice $invoice): void
    {
        $subscription = $invoice->getSubscription();
        $customerId   = $subscription->getStripeCustomerId();
        if (!$customerId) {
            return; // no payment method on file yet; invoice stays pending until the owner subscribes/pays manually
        }

        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount'   => $invoice->getAmountDueCents(),
            'currency' => strtolower($invoice->getCurrency()),
            'customer' => $customerId,
            'off_session' => true,
            'confirm'  => true,
            'metadata' => ['invoice_id' => (string) $invoice->getId()],
        ]);

        $invoice->setStripePaymentIntentId($paymentIntent->id);
    }

    public function verifyWebhookSignature(string $payload, string $sigHeader): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }
}
