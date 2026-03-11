-- Migration 007: Keap Payment Integration
-- Adds Keap-specific columns to contracts and payments tables

-- Add Keap contact ID to contracts
ALTER TABLE contracts ADD COLUMN keap_contact_id INT NULL AFTER stripe_customer_id;

-- Add Keap order ID to payments
ALTER TABLE payments ADD COLUMN keap_order_id INT NULL AFTER stripe_subscription_id;
