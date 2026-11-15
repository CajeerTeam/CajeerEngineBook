-- PostgreSQL identity format 1. Applied only by the offline installation migration.
CREATE TABLE IF NOT EXISTS cajeer_book.identity_state (
    installation text PRIMARY KEY REFERENCES cajeer_book.content_installation ON DELETE CASCADE,
    format integer NOT NULL DEFAULT 1 CHECK (format = 1),
    revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
    owner_anchor text CHECK (owner_anchor ~ '^[0-9a-f]{32}$'),
    metadata json NOT NULL CHECK (json_typeof(metadata) = 'object')
);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_users (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{32}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    username text GENERATED ALWAYS AS (value->>'username') STORED NOT NULL,
    email text GENERATED ALWAYS AS (value->>'email') STORED NOT NULL,
    CHECK (value->>'id' = record_key)
);
CREATE INDEX IF NOT EXISTS identity_users_order ON cajeer_book.identity_users(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_organizations (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{32}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    slug text GENERATED ALWAYS AS (value->>'slug') STORED NOT NULL,
    owner_id text GENERATED ALWAYS AS (value->>'owner') STORED NOT NULL,
    CHECK (value->>'id' = record_key),
    UNIQUE (installation,slug),
    FOREIGN KEY (installation,owner_id) REFERENCES cajeer_book.identity_users(installation,record_key) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_organizations_order ON cajeer_book.identity_organizations(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_workspaces (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{32}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    organization_id text GENERATED ALWAYS AS (value->>'organization') STORED NOT NULL,
    slug text GENERATED ALWAYS AS (value->>'slug') STORED NOT NULL,
    CHECK (value->>'id' = record_key),
    UNIQUE (installation,organization_id,slug),
    UNIQUE (installation,record_key,organization_id),
    content_scope text GENERATED ALWAYS AS (CASE WHEN value->>'legacy' = 'true' THEN 'legacy' ELSE (value->>'organization') || '/' || record_key END) STORED NOT NULL,
    FOREIGN KEY (installation,content_scope) REFERENCES cajeer_book.content_store(installation,scope) DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (installation,organization_id) REFERENCES cajeer_book.identity_organizations(installation,record_key) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_workspaces_order ON cajeer_book.identity_workspaces(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_sessions (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{64}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    user_id text GENERATED ALWAYS AS (value->>'user') STORED NOT NULL,
    session_id text GENERATED ALWAYS AS (value->>'id') STORED NOT NULL,
    UNIQUE (installation,session_id),
    FOREIGN KEY (installation,user_id) REFERENCES cajeer_book.identity_users(installation,record_key) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_sessions_order ON cajeer_book.identity_sessions(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_resets (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{64}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    user_id text GENERATED ALWAYS AS (value->>'user') STORED NOT NULL,
    FOREIGN KEY (installation,user_id) REFERENCES cajeer_book.identity_users(installation,record_key) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_resets_order ON cajeer_book.identity_resets(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_attempts (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{64}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key)
);
CREATE INDEX IF NOT EXISTS identity_attempts_order ON cajeer_book.identity_attempts(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_invitations (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{64}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    organization_id text GENERATED ALWAYS AS (value->>'organization') STORED NOT NULL,
    user_id text GENERATED ALWAYS AS (value->>'user') STORED,
    username text GENERATED ALWAYS AS (value->>'username') STORED NOT NULL,
    UNIQUE (installation,organization_id,username),
    FOREIGN KEY (installation,organization_id) REFERENCES cajeer_book.identity_organizations(installation,record_key) DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (installation,user_id) REFERENCES cajeer_book.identity_users(installation,record_key) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_invitations_order ON cajeer_book.identity_invitations(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_activity (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{32}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    CHECK (value->>'id' = record_key)
);
CREATE INDEX IF NOT EXISTS identity_activity_order ON cajeer_book.identity_activity(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_memberships (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{32}:[0-9a-f]{32}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    organization_id text GENERATED ALWAYS AS (value->>'organization') STORED NOT NULL,
    user_id text GENERATED ALWAYS AS (value->>'user') STORED NOT NULL,
    CHECK (record_key = organization_id || ':' || user_id),
    CHECK (value->>'role' IS NULL OR value->>'role' IN ('viewer','editor','admin')),
    UNIQUE (installation,organization_id,user_id),
    FOREIGN KEY (installation,organization_id) REFERENCES cajeer_book.identity_organizations(installation,record_key) DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (installation,user_id) REFERENCES cajeer_book.identity_users(installation,record_key) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_memberships_order ON cajeer_book.identity_memberships(installation,position);
CREATE TABLE IF NOT EXISTS cajeer_book.identity_workspace_roles (
    installation text NOT NULL REFERENCES cajeer_book.identity_state ON DELETE CASCADE,
    record_key text NOT NULL CHECK (record_key ~ '^[0-9a-f]{32}:[0-9a-f]{32}$'),
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL CHECK (json_typeof(value) = 'object'),
    PRIMARY KEY (installation,record_key),
    workspace_id text GENERATED ALWAYS AS (value->>'workspace') STORED NOT NULL,
    organization_id text GENERATED ALWAYS AS (value->>'organization') STORED NOT NULL,
    user_id text GENERATED ALWAYS AS (value->>'user') STORED NOT NULL,
    CHECK (record_key = workspace_id || ':' || user_id),
    CHECK (value->>'role' IN ('none','viewer','editor','admin')),
    FOREIGN KEY (installation,workspace_id,organization_id) REFERENCES cajeer_book.identity_workspaces(installation,record_key,organization_id) DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (installation,organization_id,user_id) REFERENCES cajeer_book.identity_memberships(installation,organization_id,user_id) DEFERRABLE INITIALLY DEFERRED
);
CREATE INDEX IF NOT EXISTS identity_workspace_roles_order ON cajeer_book.identity_workspace_roles(installation,position);
CREATE UNIQUE INDEX IF NOT EXISTS identity_username_unique ON cajeer_book.identity_users(installation,username);
CREATE UNIQUE INDEX IF NOT EXISTS identity_email_unique ON cajeer_book.identity_users(installation,email) WHERE email <> '';
CREATE UNIQUE INDEX IF NOT EXISTS identity_owner_unique ON cajeer_book.identity_users(installation) WHERE value->>'owner' = 'true';
CREATE UNIQUE INDEX IF NOT EXISTS identity_legacy_workspace_unique ON cajeer_book.identity_workspaces(installation) WHERE value->>'legacy' = 'true';
