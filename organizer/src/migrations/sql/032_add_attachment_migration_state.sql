-- Migration 032: Add attachment migration state table
-- This table tracks which emails have been processed during the RFC 2047 attachment fix migration

CREATE TABLE IF NOT EXISTS attachment_migration_state (
    id SERIAL PRIMARY KEY,
    email_id UUID NOT NULL UNIQUE REFERENCES thread_emails(id),
    folder_name VARCHAR(255) NOT NULL,
    message_id TEXT,
    status VARCHAR(20) NOT NULL, -- 'pending', 'processing', 'completed', 'skipped', 'failed'
    attachments_before INT DEFAULT 0,
    attachments_after INT DEFAULT 0,
    error_message TEXT,
    processed_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_migration_status ON attachment_migration_state(status);
CREATE INDEX IF NOT EXISTS idx_migration_email ON attachment_migration_state(email_id);

COMMENT ON TABLE attachment_migration_state IS 'Tracks progress of RFC 2047 attachment filename fix migration';
COMMENT ON COLUMN attachment_migration_state.status IS 'pending=not yet processed, processing=in progress, completed=successfully migrated, skipped=no changes needed, failed=error during migration';
