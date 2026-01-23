-- Migration 002: Coaching Rates Table for Support Package Builder
-- Run this SQL against the livewright database
--
-- This creates a rate matrix for coaching sessions that enables
-- programmatic package building with volume discounts.

-- =====================================================
-- Coaching Rates Table
-- =====================================================

CREATE TABLE IF NOT EXISTS coaching_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coach_name VARCHAR(100) NOT NULL COMMENT 'Coach name (e.g., Bob, Judith)',
    duration_minutes INT NOT NULL COMMENT 'Session duration in minutes (30, 45, 60)',
    tier INT NOT NULL COMMENT 'Number of sessions tier (1, 3, 5, 10, 20)',
    rate_per_session DECIMAL(10, 2) NOT NULL COMMENT 'Price per session at this tier',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY unique_rate (coach_name, duration_minutes, tier, deleted_at),
    INDEX idx_coach (coach_name),
    INDEX idx_duration (duration_minutes),
    INDEX idx_tier (tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Seed Data: Bob's Rates
-- =====================================================

INSERT INTO coaching_rates (coach_name, duration_minutes, tier, rate_per_session) VALUES
-- Bob 60 min
('Bob', 60, 1, 1125.00),
('Bob', 60, 3, 1025.00),
('Bob', 60, 5, 925.00),
('Bob', 60, 10, 825.00),
('Bob', 60, 20, 725.00),
-- Bob 45 min
('Bob', 45, 1, 750.00),
('Bob', 45, 3, 750.00),
('Bob', 45, 5, 700.00),
('Bob', 45, 10, 650.00),
('Bob', 45, 20, 600.00),
-- Bob 30 min
('Bob', 30, 1, 550.00),
('Bob', 30, 3, 550.00),
('Bob', 30, 5, 500.00),
('Bob', 30, 10, 450.00),
('Bob', 30, 20, 400.00);

-- =====================================================
-- Seed Data: Judith's Rates
-- =====================================================

INSERT INTO coaching_rates (coach_name, duration_minutes, tier, rate_per_session) VALUES
-- Judith 60 min
('Judith', 60, 1, 750.00),
('Judith', 60, 3, 700.00),
('Judith', 60, 5, 650.00),
('Judith', 60, 10, 600.00),
('Judith', 60, 20, 550.00),
-- Judith 45 min
('Judith', 45, 1, 560.00),
('Judith', 45, 3, 525.00),
('Judith', 45, 5, 487.50),
('Judith', 45, 10, 450.00),
('Judith', 45, 20, 412.50),
-- Judith 30 min
('Judith', 30, 1, 375.00),
('Judith', 30, 3, 350.00),
('Judith', 30, 5, 325.00),
('Judith', 30, 10, 300.00),
('Judith', 30, 20, 275.00);

-- =====================================================
-- Update support_packages JSON schema in contracts
-- Now includes: coach, duration, sessions, regular_price, package_price, savings
-- =====================================================
-- No schema change needed - we'll store richer JSON in the existing column:
-- {
--   "name": "10 x 60min Sessions with Bob",
--   "description": "Ten 60-minute coaching sessions with Bob",
--   "coach": "Bob",
--   "duration_minutes": 60,
--   "sessions": 10,
--   "regular_price": 11250.00,
--   "package_price": 8250.00,
--   "savings": 3000.00,
--   "price_monthly": 8250.00  // Legacy field for backwards compatibility
-- }
