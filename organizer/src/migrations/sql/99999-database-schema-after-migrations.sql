-- ******************************************************************
-- AUTOMATICALLY GENERATED FILE - DO NOT MODIFY
-- Generated on: 2025-09-10 15:55:10
-- 
-- This file contains the current database schema after all migrations.
-- It is NOT meant to be executed as a migration script.
-- ******************************************************************

-- ==========================================
-- TABLES
-- ==========================================

CREATE TABLE imap_folder_log (
    id integer NOT NULL DEFAULT nextval('imap_folder_log_id_seq'::regclass),
    folder_name character varying(255) NOT NULL,
    status character varying(50) NOT NULL,
    message text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE imap_folder_log ADD CONSTRAINT imap_folder_log_pkey PRIMARY KEY (id);
CREATE INDEX imap_folder_log_created_at_idx ON imap_folder_log USING btree (created_at);
CREATE INDEX imap_folder_log_folder_name_idx ON imap_folder_log USING btree (folder_name);
CREATE INDEX imap_folder_log_status_idx ON imap_folder_log USING btree (status);

CREATE TABLE imap_folder_status (
    id integer NOT NULL DEFAULT nextval('imap_folder_status_id_seq'::regclass),
    folder_name character varying(255) NOT NULL,
    thread_id uuid,
    last_checked_at timestamp with time zone,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requested_update_time timestamp with time zone
);

ALTER TABLE imap_folder_status ADD CONSTRAINT imap_folder_status_pkey PRIMARY KEY (id);
ALTER TABLE imap_folder_status ADD CONSTRAINT imap_folder_status_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX imap_folder_status_folder_name_idx ON imap_folder_status USING btree (folder_name);
CREATE INDEX imap_folder_status_folder_thread_unique ON imap_folder_status USING btree (folder_name, thread_id);
CREATE INDEX imap_folder_status_requested_update_time_idx ON imap_folder_status USING btree (requested_update_time);
CREATE INDEX imap_folder_status_thread_id_idx ON imap_folder_status USING btree (thread_id);

CREATE TABLE openai_request_log (
    id integer NOT NULL DEFAULT nextval('openai_request_log_id_seq'::regclass),
    source character varying(255) NOT NULL,
    time timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    endpoint character varying(255) NOT NULL,
    request text NOT NULL,
    response text,
    response_code integer,
    tokens_input integer,
    tokens_output integer,
    model character varying(255),
    status character varying(50)
);

ALTER TABLE openai_request_log ADD CONSTRAINT openai_request_log_pkey PRIMARY KEY (id);
CREATE INDEX openai_request_log_endpoint_idx ON openai_request_log USING btree (endpoint);
CREATE INDEX openai_request_log_source_idx ON openai_request_log USING btree (source);
CREATE INDEX openai_request_log_time_idx ON openai_request_log USING btree ("time");

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
    email_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    filename character varying(255) NOT NULL,
    filetype character varying(255) NOT NULL,
    location text NOT NULL,
    status_type character varying(50),
    status_text text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    size bigint,
    content bytea,
    id uuid NOT NULL DEFAULT gen_random_uuid()
);

ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_pkey PRIMARY KEY (id);
ALTER TABLE thread_email_attachments ADD CONSTRAINT thread_email_attachments_email_id_fkey FOREIGN KEY (email_id) REFERENCES thread_emails (id);
CREATE INDEX thread_email_attachments_email_id_id_idx ON thread_email_attachments USING btree (email_id, id);
CREATE INDEX thread_email_attachments_email_id_idx ON thread_email_attachments USING btree (email_id);
CREATE INDEX thread_email_attachments_filetype_idx ON thread_email_attachments USING btree (filetype);
CREATE INDEX thread_email_attachments_id_uuid_key ON thread_email_attachments USING btree (id);
CREATE INDEX thread_email_attachments_status_type_idx ON thread_email_attachments USING btree (status_type);

CREATE TABLE thread_email_extractions (
    extraction_id integer NOT NULL DEFAULT nextval('thread_email_extractions_id_seq'::regclass),
    email_id uuid NOT NULL,
    prompt_id character varying(255),
    prompt_text text NOT NULL,
    prompt_service character varying(50) NOT NULL,
    extracted_text text,
    error_message text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attachment_id uuid
);

ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_pkey PRIMARY KEY (extraction_id);
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_attachment_id_fkey FOREIGN KEY (attachment_id) REFERENCES thread_email_attachments (id);
ALTER TABLE thread_email_extractions ADD CONSTRAINT thread_email_extractions_email_id_fkey FOREIGN KEY (email_id) REFERENCES thread_emails (id);
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

CREATE TABLE thread_email_mapping (
    id integer NOT NULL DEFAULT nextval('thread_email_mapping_id_seq'::regclass),
    thread_id uuid NOT NULL,
    email_identifier character varying(255) NOT NULL,
    description text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_email_mapping ADD CONSTRAINT thread_email_mapping_pkey PRIMARY KEY (id);
ALTER TABLE thread_email_mapping ADD CONSTRAINT thread_email_mapping_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
CREATE INDEX thread_email_mapping_email_identifier_idx ON thread_email_mapping USING btree (email_identifier);
CREATE INDEX thread_email_mapping_thread_id_idx ON thread_email_mapping USING btree (thread_id);
CREATE INDEX thread_email_mapping_unique_idx ON thread_email_mapping USING btree (email_identifier);

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
    content_old_utf8 text,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_old character varying(255),
    imap_headers jsonb,
    content bytea NOT NULL,
    content_read_ok boolean NOT NULL DEFAULT false
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

CREATE TRIGGER update_imap_folder_status_updated_at BEFORE UPDATE ON imap_folder_status FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_thread_email_extractions_updated_at BEFORE UPDATE ON thread_email_extractions FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_thread_email_sendings_updated_at BEFORE UPDATE ON thread_email_sendings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_threads_updated_at BEFORE UPDATE ON threads FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ==========================================
-- FUNCTIONS
-- ==========================================

