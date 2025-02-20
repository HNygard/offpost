CREATE TABLE thread_email_history (
    id SERIAL PRIMARY KEY,
    thread_id UUID NOT NULL REFERENCES threads(id),
    email_id VARCHAR(255) NOT NULL,
    action VARCHAR(50) NOT NULL,
    user_id VARCHAR(255),
    details JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX thread_email_history_thread_id_idx ON thread_email_history(thread_id);
CREATE INDEX thread_email_history_email_id_idx ON thread_email_history(email_id);
