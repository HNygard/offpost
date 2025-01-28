-- Add id_old column to threads table
ALTER TABLE threads ADD COLUMN id_old VARCHAR(255);
CREATE INDEX threads_id_old_idx ON threads(id_old);
