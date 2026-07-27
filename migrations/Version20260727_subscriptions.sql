-- Subscription plans: one subscription row per organization
CREATE TABLE subscription (
    id INT AUTO_INCREMENT NOT NULL,
    organization_id INT NOT NULL,
    plan VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'trialing',
    trial_ends_at DATETIME DEFAULT NULL,
    current_period_start DATETIME DEFAULT NULL,
    current_period_end DATETIME DEFAULT NULL,
    stripe_customer_id VARCHAR(255) DEFAULT NULL,
    stripe_subscription_id VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_SUBSCRIPTION_ORG (organization_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

ALTER TABLE subscription
    ADD CONSTRAINT FK_SUBSCRIPTION_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE;

-- Backfill: give every existing organization a 1-month trial so they are not
-- silently locked out of receiving reservations after this deploy.
INSERT INTO subscription (organization_id, plan, status, trial_ends_at, created_at, updated_at)
SELECT id, 'pay_per_reservation', 'trialing', DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW(), NOW()
FROM organization;
