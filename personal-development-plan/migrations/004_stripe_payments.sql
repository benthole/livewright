-- Migration 004: Stripe Payment Integration
-- Adds deposit/payment-mode fields to pricing_options and creates payments table

-- Add deposit and payment mode to pricing_options
ALTER TABLE pricing_options
    ADD COLUMN deposit_amount DECIMAL(10,2) DEFAULT NULL AFTER price,
    ADD COLUMN payment_mode ENUM('deposit_only','deposit_and_recurring','recurring_immediate') DEFAULT 'recurring_immediate' AFTER deposit_amount;

-- Add Stripe customer ID to contracts
ALTER TABLE contracts
    ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL AFTER signed;

-- Create payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    pricing_option_id INT NOT NULL,
    stripe_customer_id VARCHAR(255) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    stripe_subscription_id VARCHAR(255) DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'usd',
    status ENUM('pending','processing','succeeded','failed','refunded') DEFAULT 'pending',
    payment_type ENUM('deposit','subscription_first','subscription_recurring','one_time') NOT NULL,
    metadata JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_contract (contract_id),
    INDEX idx_stripe_pi (stripe_payment_intent_id),
    INDEX idx_stripe_sub (stripe_subscription_id),
    INDEX idx_status (status),
    FOREIGN KEY (contract_id) REFERENCES contracts(id),
    FOREIGN KEY (pricing_option_id) REFERENCES pricing_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
