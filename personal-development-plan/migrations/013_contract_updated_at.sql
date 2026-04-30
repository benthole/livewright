-- Migration 013: Track last-modified time on contracts
-- Adds an updated_at column that auto-bumps whenever a contract row is updated,
-- so the admin dashboard can sort plans by date modified.
-- Backfills existing rows to created_at so initial ordering is sensible.

ALTER TABLE contracts
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE contracts SET updated_at = created_at WHERE updated_at IS NULL OR updated_at = '0000-00-00 00:00:00';
