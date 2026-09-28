-- Migration: Add review status and notes to thread_analysis_runs
-- Step 2c of the innsynskrav classification roadmap
-- (docs/superpowers/plans/2026-09-27-step2c-analysis-in-prod.md), "Change 9:
-- review status and notes per run (step 2b, first part)": each run gets a
-- review status plus free-text notes describing any issues found, entered by
-- an admin on /thread-analysis/thread. NOT NULL DEFAULT 'NOT_REVIEWED' so
-- every existing run gets a review status without a backfill step. See
-- docs/thread-analysis.md.

ALTER TABLE thread_analysis_runs
ADD COLUMN review_status VARCHAR(20) NOT NULL DEFAULT 'NOT_REVIEWED';

ALTER TABLE thread_analysis_runs
ADD COLUMN review_notes TEXT;

ALTER TABLE thread_analysis_runs
ADD COLUMN reviewed_by VARCHAR(255);

ALTER TABLE thread_analysis_runs
ADD COLUMN reviewed_at TIMESTAMPTZ;

ALTER TABLE thread_analysis_runs
ADD CONSTRAINT thread_analysis_runs_review_status_check
    CHECK (review_status IN ('NOT_REVIEWED', 'CORRECT', 'MINOR_ISSUES', 'WRONG'));

CREATE INDEX thread_analysis_runs_review_status_idx ON thread_analysis_runs USING btree (review_status);
