-- Version 1 of the PostgreSQL content format. Run via bin/postgres-content.php migrate.
CREATE SCHEMA IF NOT EXISTS cajeer_book;
CREATE TABLE IF NOT EXISTS cajeer_book.content_format (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    version integer NOT NULL CHECK (version > 0)
);
INSERT INTO cajeer_book.content_format(singleton,version) VALUES(true,1) ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS cajeer_book.content_installation (
    installation text PRIMARY KEY CHECK (installation ~ '^[0-9a-f]{32}$'),
    binding text NOT NULL UNIQUE CHECK (binding ~ '^[0-9a-f]{32}$')
);
CREATE TABLE IF NOT EXISTS cajeer_book.content_store (
    installation text NOT NULL CHECK (installation ~ '^[0-9a-f]{32}$'),
    scope text NOT NULL CHECK (scope = 'legacy' OR scope ~ '^[0-9a-f]{32}/[0-9a-f]{32}$'),
    revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
    PRIMARY KEY (installation, scope),
    FOREIGN KEY (installation) REFERENCES cajeer_book.content_installation ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS cajeer_book.content_section (
    installation text NOT NULL,
    scope text NOT NULL,
    name text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    kind text NOT NULL CHECK (kind IN ('array','scalar')),
    value json,
    CHECK ((kind = 'array' AND value IS NULL) OR (kind = 'scalar' AND value IS NOT NULL)),
    PRIMARY KEY (installation, scope, name),
    FOREIGN KEY (installation, scope) REFERENCES cajeer_book.content_store ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS cajeer_book.content_record (
    installation text NOT NULL,
    scope text NOT NULL,
    section text NOT NULL,
    record_key text NOT NULL,
    position integer NOT NULL CHECK (position >= 0),
    value json NOT NULL,
    PRIMARY KEY (installation, scope, section, record_key),
    FOREIGN KEY (installation, scope, section) REFERENCES cajeer_book.content_section ON DELETE CASCADE
) PARTITION BY LIST (section);
CREATE TABLE IF NOT EXISTS cajeer_book.pages PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:pages');
CREATE TABLE IF NOT EXISTS cajeer_book.media PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:media');
CREATE TABLE IF NOT EXISTS cajeer_book.history PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:history');
CREATE TABLE IF NOT EXISTS cajeer_book.books PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:books');
CREATE TABLE IF NOT EXISTS cajeer_book.reviews PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:reviews');
CREATE TABLE IF NOT EXISTS cajeer_book.publications PARTITION OF cajeer_book.content_record FOR VALUES IN ('child:ci:builds');
CREATE TABLE IF NOT EXISTS cajeer_book.ci_tokens PARTITION OF cajeer_book.content_record FOR VALUES IN ('child:ci:tokens');
CREATE TABLE IF NOT EXISTS cajeer_book.automation_jobs PARTITION OF cajeer_book.content_record FOR VALUES IN ('child:automation:jobs');
CREATE TABLE IF NOT EXISTS cajeer_book.automation_receipts PARTITION OF cajeer_book.content_record FOR VALUES IN ('child:automation:receipts');
CREATE TABLE IF NOT EXISTS cajeer_book.activity PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:activity');
CREATE TABLE IF NOT EXISTS cajeer_book.openapi PARTITION OF cajeer_book.content_record FOR VALUES IN ('root:openapi');
CREATE TABLE IF NOT EXISTS cajeer_book.other_content PARTITION OF cajeer_book.content_record DEFAULT;
CREATE INDEX IF NOT EXISTS content_record_order ON cajeer_book.content_record (installation,scope,section,position);
