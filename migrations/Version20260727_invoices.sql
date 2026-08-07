CREATE TABLE invoice (
    id INT AUTO_INCREMENT NOT NULL,
    subscription_id INT NOT NULL,
    organization_id INT NOT NULL,
    plan VARCHAR(30) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    reservation_count INT NOT NULL DEFAULT 0,
    employee_count_at_billing INT NOT NULL DEFAULT 0,
    amount_due_cents INT NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    due_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    stripe_invoice_id VARCHAR(255) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_INVOICE_PERIOD (subscription_id, period_start, period_end),
    INDEX IDX_INVOICE_ORG (organization_id),
    INDEX IDX_INVOICE_STATUS_DUE (status, due_date),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

ALTER TABLE invoice
    ADD CONSTRAINT FK_INVOICE_SUBSCRIPTION FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_INVOICE_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE;
