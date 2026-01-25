-- Migration: Add extraction_id to openai_request_log table
-- This migration adds a foreign key relationship to thread_email_extractions
-- to reliably link OpenAI requests to the extractions that triggered them

-- Add extraction_id column (nullable since existing records won't have this)
ALTER TABLE openai_request_log
ADD COLUMN extraction_id INTEGER;

-- Add foreign key constraint
ALTER TABLE openai_request_log
ADD CONSTRAINT openai_request_log_extraction_id_fkey 
FOREIGN KEY (extraction_id) 
REFERENCES thread_email_extractions (extraction_id)
ON DELETE SET NULL;

-- Add index for efficient querying
CREATE INDEX openai_request_log_extraction_id_idx 
ON openai_request_log USING btree (extraction_id);
