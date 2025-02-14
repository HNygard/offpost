-- Create thread authorizations table
CREATE TABLE thread_authorizations (
    id SERIAL PRIMARY KEY,
    thread_id UUID NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    user_id VARCHAR(255) NOT NULL,
    is_owner BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Add indexes for performance
CREATE INDEX thread_authorizations_thread_id_idx ON thread_authorizations(thread_id);
CREATE INDEX thread_authorizations_user_id_idx ON thread_authorizations(user_id);
CREATE UNIQUE INDEX thread_authorizations_thread_user_idx ON thread_authorizations(thread_id, user_id);
