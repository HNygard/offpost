ALTER TABLE thread_email_attachments ADD COLUMN content text;
ALTER TABLE thread_emails ADD COLUMN imap_headers jsonb;
