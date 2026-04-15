-- Migration 008: Add greeting field
-- Greeting appears at the top of the PDP form/page; settable in presets, editable per-PDP

ALTER TABLE contracts ADD COLUMN greeting TEXT NULL AFTER email;
ALTER TABLE pdp_presets ADD COLUMN greeting TEXT NULL AFTER name;
