-- Migration 012: Invoice PDF + email tracking on payments
-- Adds per-payment invoice generation columns. Run after migration 011.

ALTER TABLE payments
    ADD COLUMN invoice_number VARCHAR(40) NULL AFTER metadata,
    ADD COLUMN invoice_pdf_path VARCHAR(255) NULL AFTER invoice_number,
    ADD COLUMN invoice_email_sent_at DATETIME NULL AFTER invoice_pdf_path,
    ADD COLUMN invoice_email_recipient VARCHAR(255) NULL AFTER invoice_email_sent_at,
    ADD COLUMN invoice_download_token VARCHAR(64) NULL AFTER invoice_email_recipient;
