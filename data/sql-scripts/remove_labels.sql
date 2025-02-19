BEGIN;
-- First, let's create a temporary table to store the threads we'll update
CREATE TEMPORARY TABLE threads_to_update AS
SELECT DISTINCT id, labels
FROM threads 
WHERE EXISTS (SELECT 1 FROM unnest(labels) l WHERE l LIKE 'valginnsyn_2_2023_%');

-- Create a function to remove specific labels
CREATE OR REPLACE FUNCTION remove_label(labels text[], pattern text)
RETURNS text[] AS $$
BEGIN
    RETURN ARRAY(
        SELECT unnest
        FROM unnest(labels) 
        WHERE unnest NOT LIKE pattern
    );
END;
$$ LANGUAGE plpgsql;

-- Update the threads, removing the matching labels
UPDATE threads 
SET labels = remove_label(threads.labels, 'valginnsyn_2_2023_%')
FROM threads_to_update
WHERE threads.id = threads_to_update.id;

-- Output the results
SELECT 
    t.id,
    tu.labels as old_labels,
    t.labels as new_labels
FROM threads t
JOIN threads_to_update tu ON t.id = tu.id
ORDER BY t.id;

-- Drop the temporary table
DROP TABLE threads_to_update;

-- Drop the function since we don't need it anymore
DROP FUNCTION remove_label(text[], text);

ROLLBACK;
