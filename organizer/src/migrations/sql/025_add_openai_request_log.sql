-- Migration: Add OpenAI request log table
-- This migration adds a table to log OpenAI API requests for cost tracking and debugging

-- Create the table for logging OpenAI API requests
CREATE TABLE openai_request_log (
    id SERIAL PRIMARY KEY,
    source VARCHAR(255) NOT NULL,
    time TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    endpoint VARCHAR(255) NOT NULL,
    request TEXT NOT NULL,
    response TEXT,
    response_code INTEGER,
    tokens_input INTEGER,
    tokens_output INTEGER,
    model VARCHAR(255),
    status VARCHAR(50)
);

-- Add indexes for efficient querying
CREATE INDEX openai_request_log_source_idx ON openai_request_log USING btree (source);
CREATE INDEX openai_request_log_time_idx ON openai_request_log USING btree (time);
CREATE INDEX openai_request_log_endpoint_idx ON openai_request_log USING btree (endpoint);
