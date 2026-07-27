-- Track the specific Stripe PaymentMethod confirmed during setup, so off-session
-- charges can reference it explicitly instead of relying on the customer's default
-- (required for reliable PayPal reuse; cards work either way).
ALTER TABLE subscription
    ADD COLUMN stripe_payment_method_id VARCHAR(255) DEFAULT NULL AFTER stripe_subscription_id;
