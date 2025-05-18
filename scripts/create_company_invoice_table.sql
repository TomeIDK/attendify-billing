CREATE TABLE company_invoice (
    company_id VARCHAR(255) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    invoice_id BIGINT NOT NULL UNIQUE,
);