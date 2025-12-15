-- Add table for logging scheduled task execution and bandwidth usage
CREATE TABLE scheduled_task_log (
    id SERIAL PRIMARY KEY,
    task_name VARCHAR(100) NOT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMPTZ,
    status VARCHAR(50) NOT NULL DEFAULT 'running',
    bytes_processed BIGINT DEFAULT 0,
    items_processed INTEGER DEFAULT 0,
    message TEXT,
    error_message TEXT
);

-- Add indexes for efficient querying
CREATE INDEX scheduled_task_log_task_name_idx ON scheduled_task_log USING btree (task_name);
CREATE INDEX scheduled_task_log_started_at_idx ON scheduled_task_log USING btree (started_at);
CREATE INDEX scheduled_task_log_status_idx ON scheduled_task_log USING btree (status);

-- Add index for finding bandwidth-heavy tasks
CREATE INDEX scheduled_task_log_bytes_processed_idx ON scheduled_task_log USING btree (bytes_processed DESC);
