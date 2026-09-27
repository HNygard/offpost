-- Migration: Add cumulative thread state to thread_emails
-- Records the thread's state as of this email: the blob (thread_state), the
-- thread status derived from it by code (thread_state_type), and whether the
-- blob was written by a human or by an automated process (thread_state_source).
-- Nothing writes these columns yet; see docs/thread-state.md.

ALTER TABLE thread_emails
ADD COLUMN thread_state jsonb DEFAULT NULL;

ALTER TABLE thread_emails
ADD COLUMN thread_state_type VARCHAR(50) DEFAULT NULL;

ALTER TABLE thread_emails
ADD COLUMN thread_state_source VARCHAR(20) DEFAULT NULL;

ALTER TABLE thread_emails
ADD CONSTRAINT thread_emails_thread_state_source_check
    CHECK (thread_state_source IS NULL OR thread_state_source IN ('manual', 'auto'));

-- Add index for efficient filtering
CREATE INDEX thread_emails_thread_state_type_idx ON thread_emails USING btree (thread_state_type);
