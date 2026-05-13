-- Migration 017: Archive PDPs
--
-- Adds two columns so admins can archive PDPs (separate from soft-delete /
-- trash). Archived plans hide from the main list and live under a new
-- "Archived" tab. They can be unarchived (which clears both columns).

ALTER TABLE contracts
    ADD COLUMN archived_at DATETIME NULL AFTER deleted_at,
    ADD COLUMN archive_note TEXT NULL AFTER archived_at,
    ADD INDEX idx_archived_at (archived_at);
