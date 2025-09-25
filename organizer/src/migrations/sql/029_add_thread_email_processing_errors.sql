-- Migration: Add thread_email_processing_errors table
-- This table stores email processing errors for GUI resolution

-- Create thread_email_processing_errors table
CREATE TABLE thread_email_processing_errors (
    id SERIAL PRIMARY KEY,
    email_identifier character varying(255) NOT NULL,
    email_subject text NOT NULL,
    email_addresses text NOT NULL,
    error_type character varying(50) NOT NULL,
    error_message text NOT NULL,
    suggested_thread_id uuid,
    suggested_query text,
    folder_name character varying(255),
    resolved boolean DEFAULT false,
    resolved_by character varying(255),
    resolved_at timestamp with time zone,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add foreign key constraint for suggested_thread_id (can be null)
ALTER TABLE thread_email_processing_errors ADD CONSTRAINT thread_email_processing_errors_suggested_thread_id_fkey 
    FOREIGN KEY (suggested_thread_id) REFERENCES threads (id);

-- Add indexes
CREATE INDEX thread_email_processing_errors_email_identifier_idx ON thread_email_processing_errors USING btree (email_identifier);
CREATE INDEX thread_email_processing_errors_resolved_idx ON thread_email_processing_errors USING btree (resolved);
CREATE INDEX thread_email_processing_errors_error_type_idx ON thread_email_processing_errors USING btree (error_type);
CREATE INDEX thread_email_processing_errors_created_at_idx ON thread_email_processing_errors USING btree (created_at);

-- Create a unique constraint to prevent duplicate error entries for the same email identifier
CREATE UNIQUE INDEX thread_email_processing_errors_unique_idx ON thread_email_processing_errors (email_identifier) 
WHERE resolved = false;