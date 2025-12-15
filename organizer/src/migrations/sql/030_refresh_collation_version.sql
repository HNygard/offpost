-- Refresh database collation version to resolve version mismatch warnings
-- This addresses the warning that occurs when the OS collation library version
-- is updated but the database still references the old version.
-- This is a one-time operation that updates the stored collation version.

ALTER DATABASE offpost REFRESH COLLATION VERSION;
