-- Create sequence for thread_email_extractions
CREATE SEQUENCE IF NOT EXISTS thread_email_extractions_id_seq
    INCREMENT 1
    START 1
    MINVALUE 1
    MAXVALUE 2147483647
    CACHE 1;

-- Create thread_email_extractions table
CREATE TABLE thread_email_extractions (
    extraction_id integer NOT NULL DEFAULT nextval('thread_email_extractions_id_seq'::regclass),
    email_id uuid NOT NULL,
    attachment_id integer,
    prompt_id character varying(255),
    prompt_text text NOT NULL,
    prompt_service character varying(50) NOT NULL,
    extracted_text text,
    error_message text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add primary key and foreign key constraints
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_pkey PRIMARY KEY (extraction_id);
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_email_id_fkey FOREIGN KEY (email_id) REFERENCES thread_emails (id);
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_attachment_id_fkey FOREIGN KEY (attachment_id) REFERENCES thread_email_attachments (id);

-- Add indexes for performance
CREATE INDEX thread_email_extractions_email_id_idx ON thread_email_extractions USING btree (email_id);
CREATE INDEX thread_email_extractions_attachment_id_idx ON thread_email_extractions USING btree (attachment_id);
CREATE INDEX thread_email_extractions_prompt_service_idx ON thread_email_extractions USING btree (prompt_service);

-- Create trigger for updating updated_at column
CREATE TRIGGER update_thread_email_extractions_updated_at 
BEFORE UPDATE ON thread_email_extractions 
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
