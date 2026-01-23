-- Create roster_meta table for storing sync metadata
CREATE TABLE IF NOT EXISTS roster_meta (
    meta_key VARCHAR(50) PRIMARY KEY,
    meta_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert initial last_sync value
INSERT INTO roster_meta (meta_key, meta_value) VALUES ('last_sync', NULL)
ON DUPLICATE KEY UPDATE meta_key = meta_key;
