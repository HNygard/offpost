-- Migration: Add auto_classification column to thread_emails
-- This column tracks how the status was classified (e.g., 'prompt' for AI-generated)

ALTER TABLE thread_emails 
ADD COLUMN auto_classification VARCHAR(50) DEFAULT NULL;

-- Add index for efficient filtering
CREATE INDEX thread_emails_auto_classification_idx ON thread_emails USING btree (auto_classification);
