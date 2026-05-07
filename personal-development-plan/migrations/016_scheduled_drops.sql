-- Migration 016: Scheduled drops
--
-- Stores roster-drop requests with a scheduled future date. A nightly cron
-- (process_scheduled_drops.php) processes any rows whose scheduled_for has
-- arrived. Same end-state as drop_contact.php, but deferred.

CREATE TABLE IF NOT EXISTS scheduled_drops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    keap_contact_id INT NOT NULL,
    contact_name VARCHAR(255) NULL,
    contact_email VARCHAR(255) NULL,
    scheduled_for DATE NOT NULL,
    status ENUM('pending','processed','cancelled','failed') NOT NULL DEFAULT 'pending',
    snapshot JSON NULL,
    scheduled_by VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    INDEX idx_status_due (status, scheduled_for),
    INDEX idx_contact (keap_contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
