-- Add request_law_basis and request_follow_up_plan fields to threads table
ALTER TABLE threads ADD COLUMN request_law_basis character varying(50);
ALTER TABLE threads ADD COLUMN request_follow_up_plan character varying(50);

-- Create indexes for the new fields
CREATE INDEX threads_request_law_basis_idx ON threads USING btree (request_law_basis);
CREATE INDEX threads_request_follow_up_plan_idx ON threads USING btree (request_follow_up_plan);
