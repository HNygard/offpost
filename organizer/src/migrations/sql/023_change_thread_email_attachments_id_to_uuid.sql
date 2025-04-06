-- Migration: Change thread_email_attachments.id from integer to UUID
-- This migration changes the primary key of thread_email_attachments from an integer to a UUID

-- Step 1: Add a new UUID column to thread_email_attachments
ALTER TABLE thread_email_attachments ADD COLUMN id_uuid uuid NOT NULL DEFAULT gen_random_uuid();

-- Step 1.1: Add a unique constraint on the id_uuid column
ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_id_uuid_key UNIQUE (id_uuid);

-- Step 2: Create a temporary table to store the mapping between old integer IDs and new UUIDs
CREATE TEMPORARY TABLE attachment_id_mapping (
    old_id integer NOT NULL,
    new_id uuid NOT NULL
);

-- Step 3: Populate the temporary mapping table
INSERT INTO attachment_id_mapping (old_id, new_id)
SELECT id, id_uuid FROM thread_email_attachments;

-- Step 4: Update the foreign key in thread_email_extractions to use the new UUID
-- First, add a new UUID column to thread_email_extractions
ALTER TABLE thread_email_extractions ADD COLUMN attachment_id_uuid uuid;

-- Step 5: Update the new column with the corresponding UUIDs from the mapping table
UPDATE thread_email_extractions e
SET attachment_id_uuid = m.new_id
FROM attachment_id_mapping m
WHERE e.attachment_id = m.old_id;

-- Step 6: Drop the foreign key constraint from thread_email_extractions
ALTER TABLE thread_email_extractions DROP CONSTRAINT thread_email_extractions_attachment_id_fkey;

-- Step 7: Drop the old integer attachment_id column from thread_email_extractions
ALTER TABLE thread_email_extractions DROP COLUMN attachment_id;

-- Step 8: Rename the new UUID column to attachment_id
ALTER TABLE thread_email_extractions RENAME COLUMN attachment_id_uuid TO attachment_id;

-- Step 9: Add the foreign key constraint back to thread_email_extractions
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_attachment_id_fkey 
    FOREIGN KEY (attachment_id) REFERENCES thread_email_attachments (id_uuid);

-- Step 10: Drop the primary key constraint from thread_email_attachments
ALTER TABLE thread_email_attachments DROP CONSTRAINT thread_email_attachments_pkey;

-- Step 11: Drop the old integer id column from thread_email_attachments
ALTER TABLE thread_email_attachments DROP COLUMN id;

-- Step 12: Rename the new UUID column to id
ALTER TABLE thread_email_attachments RENAME COLUMN id_uuid TO id;

-- Step 13: Add the primary key constraint back to thread_email_attachments
ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_pkey PRIMARY KEY (id);

-- Step 14: Update the foreign key constraint in thread_email_extractions to reference the renamed column
ALTER TABLE thread_email_extractions DROP CONSTRAINT thread_email_extractions_attachment_id_fkey;
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_attachment_id_fkey 
    FOREIGN KEY (attachment_id) REFERENCES thread_email_attachments (id);

-- Step 15: Drop existing indexes
DROP INDEX IF EXISTS thread_email_attachments_email_id_id_idx;
DROP INDEX IF EXISTS thread_email_attachments_email_id_idx;
DROP INDEX IF EXISTS thread_email_attachments_filetype_idx;
DROP INDEX IF EXISTS thread_email_attachments_status_type_idx;

-- Step 15.1: Recreate the indexes
CREATE INDEX thread_email_attachments_email_id_id_idx ON thread_email_attachments USING btree (email_id, id);
CREATE INDEX thread_email_attachments_email_id_idx ON thread_email_attachments USING btree (email_id);
CREATE INDEX thread_email_attachments_filetype_idx ON thread_email_attachments USING btree (filetype);
CREATE INDEX thread_email_attachments_status_type_idx ON thread_email_attachments USING btree (status_type);

-- Step 16: Drop the sequence that was used for the integer IDs
DROP SEQUENCE IF EXISTS thread_email_attachments_id_seq;

-- Step 17: Drop the temporary mapping table
DROP TABLE attachment_id_mapping;
