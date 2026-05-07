-- Migration 015: Program start dates
--
-- Adds:
--   1. `program_start_date_options` lookup table (admin-managed list of dropdown
--      choices that show up on the PDP edit form).
--   2. `program_start_date` column on `contracts` (DATE, nullable). Holds the
--      date the admin selected for that client, whether picked from the dropdown
--      or entered via the calendar fallback.

CREATE TABLE IF NOT EXISTS program_start_date_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    start_date DATE NOT NULL,
    label VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_start_date (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE contracts
    ADD COLUMN program_start_date DATE NULL AFTER pathways;
