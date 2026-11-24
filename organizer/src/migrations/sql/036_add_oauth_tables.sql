-- Migration: Offpost as its own OAuth 2.1 authorization server for MCP clients
-- (docs/superpowers/plans: own OAuth server for MCP). Clients are registered
-- dynamically (RFC 7591); authorization codes and access tokens are stored
-- only as SHA-256 hashes.

CREATE TABLE oauth_clients (
    id SERIAL PRIMARY KEY,
    client_id VARCHAR(64) NOT NULL UNIQUE,
    client_secret_hash CHAR(64),
    client_name VARCHAR(100),
    redirect_uris JSONB NOT NULL,
    token_endpoint_auth_method VARCHAR(32) NOT NULL,
    registration_ip VARCHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE oauth_authorization_codes (
    id BIGSERIAL PRIMARY KEY,
    code_hash CHAR(64) NOT NULL UNIQUE,
    client_id VARCHAR(64) NOT NULL REFERENCES oauth_clients(client_id) ON DELETE CASCADE,
    user_id VARCHAR(255) NOT NULL,
    redirect_uri TEXT NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    resource TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ
);

CREATE TABLE oauth_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    client_id VARCHAR(64) NOT NULL REFERENCES oauth_clients(client_id) ON DELETE CASCADE,
    user_id VARCHAR(255) NOT NULL,
    resource TEXT NOT NULL,
    code_id BIGINT REFERENCES oauth_authorization_codes(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE oauth_registration_attempts (
    id BIGSERIAL PRIMARY KEY,
    ip VARCHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX oauth_access_tokens_user_id_idx ON oauth_access_tokens USING btree (user_id);
CREATE INDEX oauth_registration_attempts_created_at_idx ON oauth_registration_attempts USING btree (created_at);
CREATE INDEX oauth_registration_attempts_ip_created_at_idx ON oauth_registration_attempts USING btree (ip, created_at);
