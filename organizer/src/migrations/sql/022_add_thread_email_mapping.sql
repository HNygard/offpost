-- Migration: Add thread_email_mapping table
-- This table allows manual mapping of email identifiers to threads

-- Create sequence for thread_email_mapping.id
CREATE SEQUENCE IF NOT EXISTS thread_email_mapping_id_seq;

-- Create thread_email_mapping table
CREATE TABLE thread_email_mapping (
    id integer NOT NULL DEFAULT nextval('thread_email_mapping_id_seq'::regclass),
    thread_id uuid NOT NULL,
    email_identifier character varying(255) NOT NULL,
    description text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add primary key
ALTER TABLE thread_email_mapping ADD CONSTRAINT thread_email_mapping_pkey PRIMARY KEY (id);

-- Add foreign key constraint
ALTER TABLE thread_email_mapping ADD CONSTRAINT thread_email_mapping_thread_id_fkey 
    FOREIGN KEY (thread_id) REFERENCES threads (id);

-- Add indexes
CREATE INDEX thread_email_mapping_thread_id_idx ON thread_email_mapping USING btree (thread_id);
CREATE INDEX thread_email_mapping_email_identifier_idx ON thread_email_mapping USING btree (email_identifier);

-- Create a unique constraint to prevent duplicate mappings
CREATE UNIQUE INDEX thread_email_mapping_unique_idx ON thread_email_mapping (email_identifier);
