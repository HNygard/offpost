-- Migration: track when an OAuth client last had a token issued, used, revoked
-- or expired, so cleanup deletes a client a year after its last activity
-- instead of the night a months-old connection is revoked (the cached
-- client_id of claude.ai / Claude Code then keeps working for a reconnect).

ALTER TABLE oauth_clients ADD COLUMN last_active_at TIMESTAMPTZ NULL;

UPDATE oauth_clients c
SET last_active_at = (SELECT max(t.last_used_at) FROM oauth_access_tokens t WHERE t.client_id = c.client_id)
WHERE EXISTS (SELECT 1 FROM oauth_access_tokens t WHERE t.client_id = c.client_id);
