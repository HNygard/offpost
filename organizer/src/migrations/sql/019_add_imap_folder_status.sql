-- Add table for tracking IMAP folder status
CREATE TABLE imap_folder_status (
    id SERIAL PRIMARY KEY,
    folder_name VARCHAR(255) NOT NULL,
    thread_id UUID NULL,
    last_checked_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add indexes for efficient querying
CREATE INDEX imap_folder_status_folder_name_idx ON imap_folder_status USING btree (folder_name);
CREATE INDEX imap_folder_status_thread_id_idx ON imap_folder_status USING btree (thread_id);
CREATE UNIQUE INDEX imap_folder_status_folder_thread_unique ON imap_folder_status USING btree (folder_name, thread_id);

-- Add foreign key constraint
ALTER TABLE imap_folder_status ADD CONSTRAINT imap_folder_status_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);

-- Add trigger to update updated_at column
CREATE TRIGGER update_imap_folder_status_updated_at BEFORE UPDATE ON imap_folder_status FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
