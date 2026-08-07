-- Per-organization notification channels (Telegram now; WhatsApp/Viber/SMS reserved for later).
CREATE TABLE notification_channel (
    id INT AUTO_INCREMENT NOT NULL,
    organization_id INT NOT NULL,
    type VARCHAR(20) NOT NULL,
    external_id VARCHAR(255) DEFAULT NULL,
    link_token VARCHAR(64) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE INDEX UNIQ_NOTIFICATION_CHANNEL_TOKEN (link_token),
    UNIQUE INDEX UNIQ_NOTIFICATION_CHANNEL_ORG_TYPE (organization_id, type),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

ALTER TABLE notification_channel
    ADD CONSTRAINT FK_NOTIFICATION_CHANNEL_ORG FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE;

-- Track which Telegram chat/message a "new reservation" notification was sent to,
-- so its inline buttons can be finalized once the business confirms/declines.
ALTER TABLE appointment
    ADD COLUMN telegram_notified_chat_id VARCHAR(255) DEFAULT NULL,
    ADD COLUMN telegram_notified_message_id VARCHAR(255) DEFAULT NULL;
