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

-- Derived published search projection (0.4.3). Never indexes drafts or historical revisions.
CREATE TABLE IF NOT EXISTS cajeer_book.search_state (
    installation text NOT NULL, scope text NOT NULL, revision bigint NOT NULL,
    PRIMARY KEY (installation,scope),
    FOREIGN KEY (installation,scope) REFERENCES cajeer_book.content_store ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS cajeer_book.search_document (
    installation text NOT NULL, scope text NOT NULL, page_key text NOT NULL,
    book text NOT NULL, version text NOT NULL, title text NOT NULL, section text NOT NULL,
    page_order integer NOT NULL, is_public boolean NOT NULL, body text NOT NULL,
    simple_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('pg_catalog.simple'::regconfig,title),'A') ||
        setweight(to_tsvector('pg_catalog.simple'::regconfig,regexp_replace(page_key,'[^a-zA-Z0-9]+',' ','g') || ' ' || section),'B') ||
        setweight(to_tsvector('pg_catalog.simple'::regconfig,body),'D')) STORED,
    english_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('pg_catalog.english'::regconfig,title),'A') ||
        setweight(to_tsvector('pg_catalog.english'::regconfig,section),'B') ||
        setweight(to_tsvector('pg_catalog.english'::regconfig,body),'D')) STORED,
    russian_vector tsvector GENERATED ALWAYS AS (
        setweight(to_tsvector('pg_catalog.russian'::regconfig,title),'A') ||
        setweight(to_tsvector('pg_catalog.russian'::regconfig,section),'B') ||
        setweight(to_tsvector('pg_catalog.russian'::regconfig,body),'D')) STORED,
    PRIMARY KEY (installation,scope,page_key),
    FOREIGN KEY (installation,scope) REFERENCES cajeer_book.content_store ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS search_simple_gin ON cajeer_book.search_document USING gin(simple_vector);
CREATE INDEX IF NOT EXISTS search_english_gin ON cajeer_book.search_document USING gin(english_vector);
CREATE INDEX IF NOT EXISTS search_russian_gin ON cajeer_book.search_document USING gin(russian_vector);
CREATE INDEX IF NOT EXISTS search_scope_filter ON cajeer_book.search_document(installation,scope,book,version,is_public);

-- pg_trgm is trusted in PostgreSQL 16. Respect preinstalled extension schemas.
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA cajeer_book;
DO $search_indexes$
DECLARE ext_schema text;
BEGIN
    SELECT n.nspname INTO STRICT ext_schema FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='pg_trgm';
    EXECUTE format('CREATE INDEX IF NOT EXISTS search_title_trgm ON cajeer_book.search_document USING gin(title %I.gin_trgm_ops)',ext_schema);
    EXECUTE format('CREATE INDEX IF NOT EXISTS search_path_trgm ON cajeer_book.search_document USING gin(page_key %I.gin_trgm_ops)',ext_schema);
END;
$search_indexes$;

-- 0.4.6: published document language is part of the transactional search projection.
ALTER TABLE cajeer_book.search_document ADD COLUMN IF NOT EXISTS locale text NOT NULL DEFAULT 'en' CHECK (locale IN ('en','ru'));
CREATE INDEX IF NOT EXISTS search_scope_locale ON cajeer_book.search_document (installation,scope,locale);
