-- Add table for logging IMAP folder processing
CREATE TABLE imap_folder_log (
    id SERIAL PRIMARY KEY,
    folder_name VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL,
    message TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add indexes for efficient querying
CREATE INDEX imap_folder_log_folder_name_idx ON imap_folder_log USING btree (folder_name);
CREATE INDEX imap_folder_log_status_idx ON imap_folder_log USING btree (status);
CREATE INDEX imap_folder_log_created_at_idx ON imap_folder_log USING btree (created_at);
