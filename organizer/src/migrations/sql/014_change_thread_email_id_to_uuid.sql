-- Migration to change thread_emails.id from integer to UUID
-- We'll alter the existing tables and constraints without dropping them

-- First, drop the foreign key constraint from thread_email_attachments
ALTER TABLE thread_email_attachments DROP CONSTRAINT IF EXISTS thread_email_attachments_email_id_fkey;

-- Create a temporary column in thread_emails to store the new UUIDs
ALTER TABLE thread_emails ADD COLUMN id_uuid uuid DEFAULT gen_random_uuid();

-- Create a temporary table to store the mapping between old integer IDs and new UUIDs
CREATE TEMPORARY TABLE thread_email_id_mapping (
    old_id integer,
    new_id uuid
);

-- Populate the temporary mapping table and the UUID column
INSERT INTO thread_email_id_mapping (old_id, new_id)
SELECT id, id_uuid FROM thread_emails;

-- First make the email_id column nullable and add a temporary column to store old IDs
ALTER TABLE thread_email_attachments ALTER COLUMN email_id DROP NOT NULL;
ALTER TABLE thread_email_attachments ADD COLUMN email_id_old integer;

-- Store the old integer IDs before changing the column type
UPDATE thread_email_attachments SET email_id_old = email_id;

-- Change the column type to UUID (with NULL values initially)
ALTER TABLE thread_email_attachments ALTER COLUMN email_id TYPE uuid USING NULL;

-- Now update the UUID values using the mapping table and the stored old IDs
UPDATE thread_email_attachments tea
SET email_id = tem.new_id
FROM thread_email_id_mapping tem
WHERE tea.email_id_old = tem.old_id;

-- Drop the temporary column as it's no longer needed
ALTER TABLE thread_email_attachments DROP COLUMN email_id_old;

-- Now modify the thread_emails table to use UUID as primary key
ALTER TABLE thread_emails DROP CONSTRAINT thread_emails_pkey;
ALTER TABLE thread_emails ALTER COLUMN id DROP DEFAULT;
ALTER TABLE thread_emails ALTER COLUMN id TYPE uuid USING id_uuid;
ALTER TABLE thread_emails ALTER COLUMN id SET DEFAULT gen_random_uuid();
ALTER TABLE thread_emails ADD CONSTRAINT thread_emails_pkey PRIMARY KEY (id);

-- Drop the temporary UUID column as it's no longer needed
ALTER TABLE thread_emails DROP COLUMN id_uuid;

-- Recreate the foreign key constraint
ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_email_id_fkey 
    FOREIGN KEY (email_id) REFERENCES thread_emails(id);

-- Now that we've preserved the relationships between emails and attachments
-- We need to make the email_id column NOT NULL again
ALTER TABLE thread_email_attachments ALTER COLUMN email_id SET NOT NULL;
