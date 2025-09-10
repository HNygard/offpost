-- Create a function to safely convert text to bytea with proper encoding
CREATE OR REPLACE FUNCTION safe_text_to_bytea(p_text text) 
RETURNS bytea AS $$
BEGIN
    -- First try direct conversion assuming valid UTF-8
    BEGIN
        RETURN convert_to(p_text, 'UTF8');
    EXCEPTION WHEN OTHERS THEN
        -- If UTF-8 fails, try to interpret as LATIN1
        BEGIN
            RETURN convert_to(convert_from(convert_to(p_text, 'LATIN1'), 'LATIN1'), 'UTF8');
        EXCEPTION WHEN OTHERS THEN
            -- If all conversions fail, encode as escaped string
            RETURN decode(replace(p_text, E'\\', E'\\\\'), 'escape');
        END;
    END;
END;
$$ LANGUAGE plpgsql;

-- Add new content_raw column as bytea (nullable initially)
ALTER TABLE thread_emails ADD COLUMN content_raw bytea;

-- Migrate data using safe conversion
UPDATE thread_emails 
SET content_raw = safe_text_to_bytea(content);

-- Verify all rows were migrated
DO $$
DECLARE
    v_failed_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_failed_count
    FROM thread_emails
    WHERE content_raw IS NULL;
    
    IF v_failed_count > 0 THEN
        RAISE EXCEPTION 'Migration failed: % rows have NULL content_raw', v_failed_count;
    END IF;
END;
$$;

-- Make content_raw NOT NULL after successful migration
ALTER TABLE thread_emails ALTER COLUMN content_raw SET NOT NULL;

-- Rename columns (only if verification passed)
ALTER TABLE thread_emails RENAME COLUMN content TO content_old_utf8;
ALTER TABLE thread_emails RENAME COLUMN content_raw TO content;

-- Keep old column for reference, but make it nullable
ALTER TABLE thread_emails ALTER COLUMN content_old_utf8 DROP NOT NULL;

-- Drop the conversion function as it's no longer needed
DROP FUNCTION safe_text_to_bytea(text);
