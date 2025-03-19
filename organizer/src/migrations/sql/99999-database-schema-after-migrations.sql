-- ******************************************************************
-- AUTOMATICALLY GENERATED FILE - DO NOT MODIFY
-- Generated on: 2025-03-19 22:32:17
-- 
-- This file contains the current database schema after all migrations.
-- It is NOT meant to be executed as a migration script.
-- ******************************************************************

-- ==========================================
-- TABLES
-- ==========================================

CREATE TABLE thread_authorizations (
    id integer NOT NULL DEFAULT nextval('thread_authorizations_id_seq'::regclass),
    thread_id uuid NOT NULL,
    user_id character varying(255) NOT NULL,
    is_owner boolean DEFAULT false,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_authorizations ADD CONSTRAINT thread_authorizations_pkey PRIMARY KEY (id);
ALTER TABLE thread_authorizations ADD CONSTRAINT thread_authorizations_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX thread_authorizations_thread_id_idx ON thread_authorizations USING btree (thread_id);
CREATE INDEX thread_authorizations_thread_user_idx ON thread_authorizations USING btree (thread_id, user_id);
CREATE INDEX thread_authorizations_thread_user_unique ON thread_authorizations USING btree (thread_id, user_id);
CREATE INDEX thread_authorizations_user_id_idx ON thread_authorizations USING btree (user_id);

CREATE TABLE thread_email_attachments (
    id integer NOT NULL DEFAULT nextval('thread_email_attachments_id_seq'::regclass),
    email_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    filename character varying(255) NOT NULL,
    filetype character varying(255) NOT NULL,
    location text NOT NULL,
    status_type character varying(50),
    status_text text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    size bigint,
    content text
);

ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_pkey PRIMARY KEY (id);
ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_email_id_fkey FOREIGN KEY (email_id) REFERENCES thread_emails (id);
CREATE INDEX thread_email_attachments_email_id_id_idx ON thread_email_attachments USING btree (email_id, id);
CREATE INDEX thread_email_attachments_email_id_idx ON thread_email_attachments USING btree (email_id);
CREATE INDEX thread_email_attachments_filetype_idx ON thread_email_attachments USING btree (filetype);
CREATE INDEX thread_email_attachments_status_type_idx ON thread_email_attachments USING btree (status_type);

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

ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_pkey PRIMARY KEY (extraction_id);
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_attachment_id_fkey FOREIGN KEY (attachment_id) REFERENCES thread_email_attachments (id);
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_email_id_fkey FOREIGN KEY (email_id) REFERENCES thread_emails (id);
CREATE INDEX thread_email_extractions_attachment_id_idx ON thread_email_extractions USING btree (attachment_id);
CREATE INDEX thread_email_extractions_email_id_idx ON thread_email_extractions USING btree (email_id);
CREATE INDEX thread_email_extractions_prompt_service_idx ON thread_email_extractions USING btree (prompt_service);

CREATE TABLE thread_email_history (
    id integer NOT NULL DEFAULT nextval('thread_email_history_id_seq'::regclass),
    thread_id uuid NOT NULL,
    email_id character varying(255) NOT NULL,
    action character varying(50) NOT NULL,
    user_id character varying(255),
    details jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_email_history ADD CONSTRAINT thread_email_history_pkey PRIMARY KEY (id);
ALTER TABLE thread_email_history ADD CONSTRAINT thread_email_history_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX thread_email_history_email_id_idx ON thread_email_history USING btree (email_id);
CREATE INDEX thread_email_history_thread_id_idx ON thread_email_history USING btree (thread_id);
CREATE INDEX thread_email_history_user_id_idx ON thread_email_history USING btree (user_id);

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

ALTER TABLE thread_email_sendings ADD CONSTRAINT thread_email_sendings_pkey PRIMARY KEY (id);
ALTER TABLE thread_email_sendings ADD CONSTRAINT thread_email_sendings_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX thread_email_sendings_status_idx ON thread_email_sendings USING btree (status);
CREATE INDEX thread_email_sendings_thread_id_idx ON thread_email_sendings USING btree (thread_id);

CREATE TABLE thread_emails (
    id uuid NOT NULL DEFAULT gen_random_uuid(),
    thread_id uuid NOT NULL,
    timestamp_received timestamp with time zone NOT NULL,
    datetime_received timestamp with time zone,
    ignore boolean DEFAULT false,
    email_type character varying(50),
    status_type character varying(50),
    status_text text,
    description text,
    answer text,
    content text NOT NULL,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_old character varying(255),
    imap_headers jsonb
);

ALTER TABLE thread_emails ADD CONSTRAINT thread_emails_pkey PRIMARY KEY (id);
ALTER TABLE thread_emails ADD CONSTRAINT thread_emails_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX thread_emails_email_type_idx ON thread_emails USING btree (email_type);
CREATE INDEX thread_emails_id_old_idx ON thread_emails USING btree (id_old);
CREATE INDEX thread_emails_ignore_idx ON thread_emails USING btree (ignore);
CREATE INDEX thread_emails_status_type_idx ON thread_emails USING btree (status_type);
CREATE INDEX thread_emails_thread_datetime_idx ON thread_emails USING btree (thread_id, datetime_received);
CREATE INDEX thread_emails_thread_id_idx ON thread_emails USING btree (thread_id);
CREATE INDEX thread_emails_timestamp_received_idx ON thread_emails USING btree (timestamp_received);

CREATE TABLE thread_history (
    id integer NOT NULL DEFAULT nextval('thread_history_id_seq'::regclass),
    thread_id uuid NOT NULL,
    action character varying(50) NOT NULL,
    user_id character varying(255) NOT NULL,
    details jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_history ADD CONSTRAINT thread_history_pkey PRIMARY KEY (id);
ALTER TABLE thread_history ADD CONSTRAINT thread_history_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX thread_history_thread_id_idx ON thread_history USING btree (thread_id);
CREATE INDEX thread_history_user_id_idx ON thread_history USING btree (user_id);

CREATE TABLE threads (
    id uuid NOT NULL,
    entity_id character varying(255) NOT NULL,
    title text,
    my_name character varying(255) NOT NULL,
    my_email character varying(255) NOT NULL,
    labels ARRAY,
    sent boolean DEFAULT false,
    archived boolean DEFAULT false,
    public boolean DEFAULT false,
    sent_comment text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_old character varying(255),
    sending_status character varying(20) DEFAULT 'STAGING'::character varying,
    initial_request text,
    request_law_basis character varying(50),
    request_follow_up_plan character varying(50)
);

ALTER TABLE threads ADD CONSTRAINT threads_pkey PRIMARY KEY (id);
CREATE INDEX threads_archived_idx ON threads USING btree (archived);
CREATE INDEX threads_entity_created_idx ON threads USING btree (entity_id, created_at);
CREATE INDEX threads_entity_id_idx ON threads USING btree (entity_id);
CREATE INDEX threads_id_old_idx ON threads USING btree (id_old);
CREATE INDEX threads_labels_idx ON threads USING gin (labels);
CREATE INDEX threads_my_email_idx ON threads USING btree (my_email);
CREATE INDEX threads_request_follow_up_plan_idx ON threads USING btree (request_follow_up_plan);
CREATE INDEX threads_request_law_basis_idx ON threads USING btree (request_law_basis);
CREATE INDEX threads_sending_status_idx ON threads USING btree (sending_status);
CREATE INDEX threads_sent_idx ON threads USING btree (sent);
CREATE INDEX threads_updated_at_idx ON threads USING btree (updated_at);

CREATE OR REPLACE FUNCTION update_updated_at_column() RETURNS trigger AS $$ 
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
 $$ LANGUAGE plpgsql;

CREATE TRIGGER update_thread_email_extractions_updated_at BEFORE UPDATE ON thread_email_extractions FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_thread_email_sendings_updated_at BEFORE UPDATE ON thread_email_sendings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_threads_updated_at BEFORE UPDATE ON threads FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ==========================================
-- FUNCTIONS
-- ==========================================

