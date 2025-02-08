-- Add id_old column to thread_emails table
ALTER TABLE thread_emails ADD COLUMN id_old VARCHAR(255);
CREATE INDEX thread_emails_id_old_idx ON thread_emails(id_old);
