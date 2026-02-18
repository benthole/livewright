-- Migration: Add signature, TOS, scholarship amount, and update application_type
-- Date: 2026-02-18

-- Change application_type from ENUM to VARCHAR to support multiple types (comma-separated)
ALTER TABLE scholarship_applications
    MODIFY COLUMN application_type VARCHAR(100) NOT NULL;

-- Add signature and TOS fields
ALTER TABLE scholarship_applications
    ADD COLUMN signature_name VARCHAR(200) AFTER documentation_files,
    ADD COLUMN tos_agreed TINYINT(1) DEFAULT 0 AFTER signature_name;

-- Add scholarship amount (admin-assigned)
ALTER TABLE scholarship_applications
    ADD COLUMN scholarship_amount VARCHAR(20) DEFAULT NULL AFTER admin_notes;
