-- Add new columns
ALTER TABLE threads ADD COLUMN sending_status VARCHAR(20) DEFAULT 'STAGED';
ALTER TABLE threads ADD COLUMN initial_request TEXT;

-- Migrate existing data
UPDATE threads SET sending_status = 'SENT' WHERE sent = true;
UPDATE threads SET sending_status = 'STAGED' WHERE sent = false;

-- Create index for new column
CREATE INDEX threads_sending_status_idx ON threads USING btree (sending_status);
