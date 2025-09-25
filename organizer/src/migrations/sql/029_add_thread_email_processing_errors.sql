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

-- Create a unique constraint to prevent duplicate error entries for the same email identifier
CREATE UNIQUE INDEX thread_email_processing_errors_unique_idx ON thread_email_processing_errors (email_identifier) 
WHERE resolved = false;