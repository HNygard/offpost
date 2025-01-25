-- Create threads table
CREATE TABLE threads (
    id SERIAL PRIMARY KEY,
    external_id UUID NOT NULL,
    municipality_id VARCHAR(255) NOT NULL,
    profile_first_name VARCHAR(255) NOT NULL,
    profile_last_name VARCHAR(255) NOT NULL,
    profile_email VARCHAR(255) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX threads_external_id_idx ON threads(external_id);
CREATE INDEX threads_municipality_id_idx ON threads(municipality_id);
CREATE UNIQUE INDEX threads_profile_email_idx ON threads(profile_email);

-- Create emails table
CREATE TABLE emails (
    id SERIAL PRIMARY KEY,
    thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    direction VARCHAR(3) NOT NULL CHECK (direction IN ('IN', 'OUT')),
    subject TEXT NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    received_date TIMESTAMPTZ NOT NULL,
    content TEXT NOT NULL,
    raw_content TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX emails_thread_id_idx ON emails(thread_id);
CREATE INDEX emails_direction_idx ON emails(direction);
CREATE INDEX emails_received_date_idx ON emails(received_date);

-- Create attachments table
CREATE TABLE attachments (
    id SERIAL PRIMARY KEY,
    email_id INTEGER NOT NULL REFERENCES emails(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(255) NOT NULL,
    size INTEGER NOT NULL,
    content BYTEA NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX attachments_email_id_idx ON attachments(email_id);

-- Create thread metadata table
CREATE TABLE thread_metadata (
    id SERIAL PRIMARY KEY,
    thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    key VARCHAR(255) NOT NULL,
    value TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX thread_metadata_thread_id_idx ON thread_metadata(thread_id);
CREATE INDEX thread_metadata_key_idx ON thread_metadata(key);

-- Create function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Create triggers for updated_at columns
CREATE TRIGGER update_threads_updated_at
    BEFORE UPDATE ON threads
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_thread_metadata_updated_at
    BEFORE UPDATE ON thread_metadata
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
