-- Add new column
ALTER TABLE thread_emails ADD COLUMN content_read_status varchar(10) DEFAULT NULL;

-- Copy data from old column
UPDATE thread_emails SET content_read_status = 'success' WHERE content_read_ok = true;

-- Drop old column
ALTER TABLE thread_emails DROP COLUMN content_read_ok;
