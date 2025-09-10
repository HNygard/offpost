-- Add new content_raw column as bytea (nullable initially)
ALTER TABLE thread_emails ADD COLUMN content_raw bytea;

-- Migrate data from content to content_raw using UTF-8 encoding
-- First attempt: Try direct conversion assuming UTF-8
UPDATE thread_emails 
SET content_raw = content::bytea;

-- Make content_raw NOT NULL after data migration
ALTER TABLE thread_emails ALTER COLUMN content_raw SET NOT NULL;

-- Rename content_raw to content
ALTER TABLE thread_emails RENAME COLUMN content TO content_old_utf8;
ALTER TABLE thread_emails RENAME COLUMN content_raw TO content;

-- Old content column is kept for reference, can be dropped later
ALTER TABLE thread_emails ALTER COLUMN content_old_utf8 DROP NOT NULL;