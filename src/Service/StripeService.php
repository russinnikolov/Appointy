<?php

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Organization;
use App\Entity\Subscription;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe SDK — the single point of contact with Stripe's API.
<<<<<<< HEAD
 *
 * All 4 plans are billed the same way: Checkout only ever collects/confirms a saved
 * payment method (mode 'setup'), never a native Stripe recurring Subscription/Price.
 * The recurring charge itself is computed and collected off-session by our own
 * monthly cron (see GenerateMonthlyInvoicesCommand + PlanCatalog::amountDueCents()).
 * This is deliberate: enterprise has a variable per-employee overage a fixed Stripe
 * Price can't express, and keeping every plan on the same billing path means changing
 * plans is a plain local update — no Stripe subscription to swap/cancel/prorate, and
 * no risk of a business being billed twice (once by Stripe's own subscription cycle,
 * once by our cron) the way a 'subscription'-mode Checkout would create.
=======
 * Stripe Price IDs for the 3 flat-fee plans are read from env vars since there is no
 * admin UI to manage them; the pay-per-reservation plan has no fixed Price (variable amount).
 *
 * Payment methods: Apple Pay and Google Pay ride along automatically under the 'card'
 * payment method type in Stripe Checkout — no extra code is needed, only Dashboard
 * toggles (Apple Pay is on by default; Google Pay must be enabled explicitly). PayPal
 * is requested explicitly below and must also be enabled for the Stripe account in the
 * Dashboard (Settings → Payment methods) before it will actually appear at checkout.
>>>>>>> 95dda2456a91fff7a03f5ae5a9fbb411f735d51f
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

    /** Collects/confirms a payment method for future off-session charging — never creates a Stripe Subscription. */
    public function createSetupSession(Subscription $subscription, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $customerId = $this->ensureCustomer($subscription);

        return $this->stripe->checkout->sessions->create([
<<<<<<< HEAD
            'mode'                 => 'setup',
            'customer'             => $customerId,
            // Apple Pay / Google Pay ride along automatically under 'card'; PayPal must
            // also be enabled for "recurring payments" on the Stripe account (Dashboard)
            // before a saved PayPal method can actually be charged off-session later.
=======
            'mode'                 => 'subscription',
            'customer'             => $customerId,
            'line_items'           => [['price' => $priceId, 'quantity' => 1]],
            // Apple Pay / Google Pay are offered automatically under 'card'; 'paypal'
            // is opted into explicitly since Stripe does not enable it by default.
>>>>>>> 95dda2456a91fff7a03f5ae5a9fbb411f735d51f
            'payment_method_types' => ['card', 'paypal'],
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
        ]);
    }

    /** Resolves the PaymentMethod a completed SetupIntent confirmed, so it can be stored for explicit off-session reuse. */
    public function resolveSetupPaymentMethod(string $setupIntentId): ?string
    {
        $setupIntent = $this->stripe->setupIntents->retrieve($setupIntentId);

        return $setupIntent->payment_method ?: null;
    }

    /** Whether the organization already has a payment method on file (no Checkout round-trip needed to change plans). */
    public function hasPaymentMethodOnFile(Subscription $subscription): bool
    {
        $customerId = $subscription->getStripeCustomerId();
        if (!$customerId) {
            return false;
        }

        $methods = $this->stripe->paymentMethods->all(['customer' => $customerId, 'limit' => 1]);

        return count($methods->data) > 0;
    }

    /** Off-session charge for a variable-amount invoice (pay-per-reservation, enterprise overage). */
    public function chargeInvoice(Invoice $invoice): void
    {
        $subscription = $invoice->getSubscription();
        $customerId   = $subscription->getStripeCustomerId();
        if (!$customerId) {
            return; // no payment method on file yet; invoice stays pending until the owner subscribes/pays manually
        }

        $params = [
            'amount'      => $invoice->getAmountDueCents(),
            'currency'    => strtolower($invoice->getCurrency()),
            'customer'    => $customerId,
            'off_session' => true,
            'confirm'     => true,
            'metadata'    => ['invoice_id' => (string) $invoice->getId()],
        ];

        // Reference the exact saved PaymentMethod rather than relying on the customer's
        // "default" one — Stripe does not reliably resolve a default for PayPal.
        if ($subscription->getStripePaymentMethodId()) {
            $params['payment_method'] = $subscription->getStripePaymentMethodId();
        }

        $paymentIntent = $this->stripe->paymentIntents->create($params);

        $invoice->setStripePaymentIntentId($paymentIntent->id);
    }

    public function verifyWebhookSignature(string $payload, string $sigHeader): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }
}
