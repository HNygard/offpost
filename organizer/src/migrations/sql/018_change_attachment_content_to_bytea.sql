-- Change thread_email_attachments.content from TEXT to BYTEA
ALTER TABLE thread_email_attachments ALTER COLUMN content TYPE bytea USING content::bytea;
