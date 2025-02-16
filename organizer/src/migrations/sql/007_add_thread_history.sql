CREATE TABLE thread_history (
    id SERIAL PRIMARY KEY,
    thread_id UUID NOT NULL REFERENCES threads(id),
    action VARCHAR(50) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    details JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX thread_history_thread_id_idx ON thread_history(thread_id);
