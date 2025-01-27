-- Create threads table
CREATE TABLE threads (
    id UUID PRIMARY KEY,
    entity_id VARCHAR(255) NOT NULL,
    title TEXT,
    my_name VARCHAR(255) NOT NULL,
    my_email VARCHAR(255) NOT NULL,
    labels TEXT[],
    sent BOOLEAN DEFAULT FALSE,
    archived BOOLEAN DEFAULT FALSE,
    public BOOLEAN DEFAULT FALSE,
    sent_comment TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX threads_my_email_idx ON threads(my_email);
CREATE INDEX threads_entity_id_idx ON threads(entity_id);
CREATE INDEX threads_archived_idx ON threads(archived);
CREATE INDEX threads_sent_idx ON threads(sent);
CREATE INDEX threads_labels_idx ON threads USING GIN(labels);

-- Create emails table
CREATE TABLE thread_emails (
    id SERIAL PRIMARY KEY,
    thread_id UUID NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    timestamp_received TIMESTAMPTZ NOT NULL,
    datetime_received TIMESTAMPTZ,
    ignore BOOLEAN DEFAULT FALSE,
    email_type VARCHAR(50),
    status_type VARCHAR(50),
    status_text TEXT,
    description TEXT,
    answer TEXT,
    content TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX thread_emails_thread_id_idx ON thread_emails(thread_id);
CREATE INDEX thread_emails_timestamp_received_idx ON thread_emails(timestamp_received);
CREATE INDEX thread_emails_email_type_idx ON thread_emails(email_type);
CREATE INDEX thread_emails_status_type_idx ON thread_emails(status_type);
CREATE INDEX thread_emails_ignore_idx ON thread_emails(ignore);

-- Create email attachments table
CREATE TABLE thread_email_attachments (
    id SERIAL PRIMARY KEY,
    email_id INTEGER NOT NULL REFERENCES thread_emails(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filetype VARCHAR(255) NOT NULL,
    location TEXT NOT NULL,
    status_type VARCHAR(50),
    status_text TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX thread_email_attachments_email_id_idx ON thread_email_attachments(email_id);
CREATE INDEX thread_email_attachments_filetype_idx ON thread_email_attachments(filetype);
CREATE INDEX thread_email_attachments_status_type_idx ON thread_email_attachments(status_type);

-- Create function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Create trigger for updated_at column
CREATE TRIGGER update_threads_updated_at
    BEFORE UPDATE ON threads
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
