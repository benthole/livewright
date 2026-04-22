-- Migration 010: Add pathways section to contracts
-- Rich-text section displayed under the From/Toward on the PDP page.
-- Default content lives in PHP (lib/pathways_default.php) and is applied
-- at render/edit time when the column is NULL or empty.

ALTER TABLE contracts ADD COLUMN pathways TEXT NULL AFTER pdp_toward;
