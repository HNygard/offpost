-- Add requested_update_time column to imap_folder_status table
ALTER TABLE imap_folder_status ADD COLUMN requested_update_time TIMESTAMPTZ NULL;

-- Add index for efficient querying
CREATE INDEX imap_folder_status_requested_update_time_idx ON imap_folder_status USING btree (requested_update_time);

-- Comment for documentation
COMMENT ON COLUMN imap_folder_status.requested_update_time IS 'Timestamp when an update was requested for this folder, NULL if not requested';
