-- Update existing data
UPDATE threads SET sending_status = 'STAGING' WHERE sending_status = 'STAGED';

-- Update default value for new rows
ALTER TABLE threads ALTER COLUMN sending_status SET DEFAULT 'STAGING';
