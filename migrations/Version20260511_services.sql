-- Services feature: service catalogue, employee assignments, appointment linkage

CREATE TABLE service (
    id              INT AUTO_INCREMENT NOT NULL,
    organization_id INT NOT NULL,
    name            VARCHAR(150) NOT NULL,
    description     LONGTEXT DEFAULT NULL,
    price           DECIMAL(10,2) DEFAULT NULL,
    duration_minutes INT NOT NULL DEFAULT 30,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    INDEX IDX_SERVICE_ORG (organization_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE service_employee (
    service_id  INT NOT NULL,
    employee_id INT NOT NULL,
    INDEX IDX_SE_SERVICE  (service_id),
    INDEX IDX_SE_EMPLOYEE (employee_id),
    PRIMARY KEY (service_id, employee_id)
) DEFAULT CHARACTER SET utf8mb4;

ALTER TABLE service
    ADD CONSTRAINT FK_SERVICE_ORG
        FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE;

ALTER TABLE service_employee
    ADD CONSTRAINT FK_SE_SERVICE
        FOREIGN KEY (service_id)  REFERENCES service  (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_SE_EMPLOYEE
        FOREIGN KEY (employee_id) REFERENCES employee (id) ON DELETE CASCADE;

ALTER TABLE appointment
    ADD COLUMN service_id INT DEFAULT NULL,
    ADD INDEX IDX_APPT_SERVICE (service_id),
    ADD CONSTRAINT FK_APPT_SERVICE
        FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE SET NULL;
