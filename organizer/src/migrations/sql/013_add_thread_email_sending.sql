-- Create sequence for thread_email_sendings
CREATE SEQUENCE IF NOT EXISTS thread_email_sendings_id_seq
    INCREMENT 1
    START 1
    MINVALUE 1
    MAXVALUE 2147483647
    CACHE 1;

-- Create thread_email_sendings table
CREATE TABLE thread_email_sendings (
    id integer NOT NULL DEFAULT nextval('thread_email_sendings_id_seq'::regclass),
    thread_id uuid NOT NULL,
    email_content text NOT NULL,
    email_subject text NOT NULL,
    email_to text NOT NULL,
    email_from text NOT NULL,
    email_from_name text NOT NULL,
    status character varying(20) DEFAULT 'STAGING'::character varying,
    smtp_response text,
    smtp_debug text,
    error_message text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add primary key and foreign key constraints
ALTER TABLE thread_email_sendings ADD CONSTRAINT thread_email_sendings_pkey PRIMARY KEY (id);
ALTER TABLE thread_email_sendings ADD CONSTRAINT thread_email_sendings_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);

-- Add indexes for performance
CREATE INDEX thread_email_sendings_thread_id_idx ON thread_email_sendings USING btree (thread_id);
CREATE INDEX thread_email_sendings_status_idx ON thread_email_sendings USING btree (status);

-- Create trigger for updating updated_at column
CREATE TRIGGER update_thread_email_sendings_updated_at 
BEFORE UPDATE ON thread_email_sendings 
FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
