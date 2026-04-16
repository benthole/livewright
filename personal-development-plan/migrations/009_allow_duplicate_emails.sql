-- Migration 009: Allow multiple plans per email
-- Drops the UNIQUE constraint on contracts.email so the same person can have multiple PDPs

ALTER TABLE contracts DROP INDEX email;
ALTER TABLE contracts ADD INDEX idx_email (email);
