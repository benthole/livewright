-- Migration: Replace individual income fields with gross household income + dependents
-- Date: 2026-02-27

ALTER TABLE scholarship_applications
    ADD COLUMN gross_household_income VARCHAR(100) AFTER other_phone,
    ADD COLUMN dependents VARCHAR(50) AFTER gross_household_income;
