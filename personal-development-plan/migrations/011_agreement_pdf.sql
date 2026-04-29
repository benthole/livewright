-- Migration 011: Agreement PDF generation + email tracking
-- Run after migration 010.

ALTER TABLE contracts
    ADD COLUMN agreement_pdf_path VARCHAR(255) NULL AFTER pathways,
    ADD COLUMN agreement_signed_at DATETIME NULL AFTER agreement_pdf_path,
    ADD COLUMN agreement_email_sent_at DATETIME NULL AFTER agreement_signed_at,
    ADD COLUMN agreement_email_recipient VARCHAR(255) NULL AFTER agreement_email_sent_at,
    ADD COLUMN agreement_download_token VARCHAR(64) NULL AFTER agreement_email_recipient;
