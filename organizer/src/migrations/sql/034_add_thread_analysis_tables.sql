-- Migration: Add thread analysis storage tables
-- Storage for step 2c of the innsynskrav classification roadmap
-- (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md): a worker on
-- the owner's machine analyses threads with headless Claude Code and posts
-- results here; prod applies a done run to thread_emails. See
-- docs/thread-analysis.md, "Storage in prod".

-- The system prompt text, stored once per version (content-addressed by its
-- sha256), so runs referencing the same prompt don't duplicate it.
CREATE TABLE thread_analysis_system_prompts (
    sha256 CHAR(64) NOT NULL,
    text TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_analysis_system_prompts ADD CONSTRAINT thread_analysis_system_prompts_pkey PRIMARY KEY (sha256);

-- One analysis of one thread. Also the queue: a row starts as 'requested',
-- a worker claims it ('claimed', with a lease), then reports 'done' or
-- 'failed'. 'cancelled' is available for later changes.
CREATE TABLE thread_analysis_runs (
    id BIGSERIAL PRIMARY KEY,
    thread_id UUID NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'requested',
    mode VARCHAR(20) NOT NULL,
    requested_by VARCHAR(255) NOT NULL,
    requested_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at TIMESTAMPTZ,
    lease_expires_at TIMESTAMPTZ,
    worker VARCHAR(255),
    model VARCHAR(255),
    system_prompt_sha256 CHAR(64),
    schema_version INTEGER,
    finished_at TIMESTAMPTZ,
    error TEXT
);

ALTER TABLE thread_analysis_runs ADD CONSTRAINT thread_analysis_runs_thread_id_fkey FOREIGN KEY (thread_id) REFERENCES threads (id);
ALTER TABLE thread_analysis_runs ADD CONSTRAINT thread_analysis_runs_system_prompt_sha256_fkey FOREIGN KEY (system_prompt_sha256) REFERENCES thread_analysis_system_prompts (sha256);
ALTER TABLE thread_analysis_runs ADD CONSTRAINT thread_analysis_runs_status_check
    CHECK (status IN ('requested', 'claimed', 'done', 'failed', 'cancelled'));
ALTER TABLE thread_analysis_runs ADD CONSTRAINT thread_analysis_runs_mode_check
    CHECK (mode IN ('incremental', 'full'));

CREATE INDEX thread_analysis_runs_status_requested_at_idx ON thread_analysis_runs USING btree (status, requested_at);
CREATE INDEX thread_analysis_runs_thread_id_idx ON thread_analysis_runs USING btree (thread_id);

-- The provider-neutral result for one event (email) in a run.
CREATE TABLE thread_analysis_events (
    id BIGSERIAL PRIMARY KEY,
    run_id BIGINT NOT NULL,
    email_id UUID NOT NULL,
    "position" INTEGER NOT NULL,
    email_type VARCHAR(50),
    email_note TEXT,
    email_type_gap TEXT,
    thread_state JSONB,
    derived_thread_state_type VARCHAR(50),
    attempts INTEGER NOT NULL DEFAULT 1,
    error TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_analysis_events ADD CONSTRAINT thread_analysis_events_run_id_fkey FOREIGN KEY (run_id) REFERENCES thread_analysis_runs (id);
ALTER TABLE thread_analysis_events ADD CONSTRAINT thread_analysis_events_email_id_fkey FOREIGN KEY (email_id) REFERENCES thread_emails (id);
ALTER TABLE thread_analysis_events ADD CONSTRAINT thread_analysis_events_run_id_position_key UNIQUE (run_id, "position");

CREATE INDEX thread_analysis_events_run_id_idx ON thread_analysis_events USING btree (run_id);
CREATE INDEX thread_analysis_events_email_id_idx ON thread_analysis_events USING btree (email_id);

-- Everything Anthropic-specific: one row per call to headless Claude Code,
-- retries included. openai_request_log stays as-is, for OpenAI.
CREATE TABLE thread_analysis_claude_code_calls (
    id BIGSERIAL PRIMARY KEY,
    run_id BIGINT NOT NULL,
    event_id BIGINT NOT NULL,
    attempt INTEGER NOT NULL,
    input_text TEXT NOT NULL,
    json_schema JSONB,
    response JSONB,
    model VARCHAR(255),
    model_resolved VARCHAR(255),
    session_id VARCHAR(255),
    claude_code_version VARCHAR(50),
    input_tokens INTEGER,
    cache_creation_input_tokens INTEGER,
    cache_read_input_tokens INTEGER,
    output_tokens INTEGER,
    thinking_tokens INTEGER,
    cost_usd NUMERIC(12,6),
    duration_ms INTEGER,
    duration_api_ms INTEGER,
    is_error BOOLEAN,
    stop_reason VARCHAR(50),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE thread_analysis_claude_code_calls ADD CONSTRAINT thread_analysis_claude_code_calls_run_id_fkey FOREIGN KEY (run_id) REFERENCES thread_analysis_runs (id);
ALTER TABLE thread_analysis_claude_code_calls ADD CONSTRAINT thread_analysis_claude_code_calls_event_id_fkey FOREIGN KEY (event_id) REFERENCES thread_analysis_events (id);

CREATE INDEX thread_analysis_claude_code_calls_run_id_idx ON thread_analysis_claude_code_calls USING btree (run_id);
CREATE INDEX thread_analysis_claude_code_calls_event_id_idx ON thread_analysis_claude_code_calls USING btree (event_id);
