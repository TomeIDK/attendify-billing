-- create company table
CREATE TABLE company (
    uid BIGINT UNSIGNED PRIMARY KEY,
    owner_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    company_number VARCHAR(255) NOT NULL,
    vat_number VARCHAR(255) NOT NULL,
    address_street VARCHAR(255) NOT NULL,
    address_number VARCHAR(255) NOT NULL,
    address_postcode VARCHAR(255) NOT NULL,
    address_city VARCHAR(255) NOT NULL,
    billing_address_street VARCHAR(255) NOT NULL,
    billing_address_number VARCHAR(255) NOT NULL,
    billing_address_postcode VARCHAR(255) NOT NULL,
    billing_address_city VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
);
