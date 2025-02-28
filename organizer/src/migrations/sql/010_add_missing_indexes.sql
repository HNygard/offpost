-- Add missing indexes to improve query performance

-- Add unique constraint on thread_authorizations (thread_id, user_id)
-- This is needed for the ON CONFLICT clause in ThreadAuthorizationManager::addUserToThread()
ALTER TABLE thread_authorizations ADD CONSTRAINT thread_authorizations_thread_user_unique UNIQUE (thread_id, user_id);

-- Add composite index on thread_emails (thread_id, datetime_received)
-- This will improve performance for queries that filter by thread_id and sort by datetime_received
CREATE INDEX thread_emails_thread_datetime_idx ON thread_emails USING btree (thread_id, datetime_received);

-- Add composite index on thread_email_attachments (email_id, id)
-- This will improve performance for queries that join thread_email_attachments and order by id
CREATE INDEX thread_email_attachments_email_id_id_idx ON thread_email_attachments USING btree (email_id, id);

-- Add index on thread_history (user_id)
-- This will improve performance for queries that filter by user_id
CREATE INDEX thread_history_user_id_idx ON thread_history USING btree (user_id);

-- Add index on thread_email_history (user_id)
-- This will improve performance for queries that filter by user_id
CREATE INDEX thread_email_history_user_id_idx ON thread_email_history USING btree (user_id);

-- Add composite index on threads (entity_id, created_at)
-- This will improve performance for queries that filter by entity_id and sort by created_at
CREATE INDEX threads_entity_created_idx ON threads USING btree (entity_id, created_at);

-- Add index on threads (updated_at)
-- This will improve performance for queries that sort by updated_at
CREATE INDEX threads_updated_at_idx ON threads USING btree (updated_at);
