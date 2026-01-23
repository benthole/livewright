-- Migration 001: Pricing and Support Packages
-- Run this SQL against the livewright database
--
-- IMPORTANT: Run these statements one at a time if needed.
-- Some may fail if they've already been applied.

-- =====================================================
-- Phase 1: No schema changes needed for pricing reorder
-- The pricing model change is UI/logic only - we still
-- store monthly, quarterly, yearly prices, just the
-- base calculation and display order changes.
--
-- PRICING MODEL CHANGE:
-- OLD: Monthly is base, quarterly = monthly*3 - 5%, yearly = monthly*12 - 10%
-- NEW: Annual is base, quarterly = annual/4 + 5%, monthly = annual/12 + 10%
-- =====================================================

-- =====================================================
-- Phase 2: Support Packages
-- =====================================================

-- Add support_packages JSON column to contracts table
-- This stores optional support packages per contract
-- Run this only if the column doesn't exist:
ALTER TABLE contracts
ADD COLUMN support_packages JSON DEFAULT NULL
COMMENT 'JSON array of support packages: [{name, description, price_monthly}]';

-- Create support package presets table for global presets
CREATE TABLE IF NOT EXISTS support_package_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Preset name (e.g., "Standard Support Menu")',
    description TEXT COMMENT 'Description of this preset menu',
    packages JSON NOT NULL COMMENT 'JSON array of packages: [{name, description, price_monthly}]',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY unique_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample support package presets (optional - only if you want examples)
-- DELETE FROM support_package_presets; -- Uncomment to clear existing presets first
INSERT INTO support_package_presets (name, description, packages) VALUES
('Standard Support Menu', 'Default support packages for most clients', '[
    {"name": "Email Support", "description": "Unlimited email support with 24-hour response time", "price_monthly": 49},
    {"name": "Priority Support", "description": "Priority email + phone support with 4-hour response time", "price_monthly": 149},
    {"name": "VIP Support", "description": "Dedicated support channel with 1-hour response time + weekly check-ins", "price_monthly": 299}
]'),
('Executive Support Menu', 'Premium support packages for executive clients', '[
    {"name": "Executive Email", "description": "Priority email support with same-day response", "price_monthly": 199},
    {"name": "Executive Phone", "description": "Dedicated phone line + email with 2-hour response", "price_monthly": 399},
    {"name": "White Glove", "description": "24/7 dedicated support + weekly strategy calls", "price_monthly": 799}
]')
ON DUPLICATE KEY UPDATE name = name; -- Ignore if already exists
