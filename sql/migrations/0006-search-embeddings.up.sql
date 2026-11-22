-- Derived embedding cache. Deleting/unpublishing a document cascades immediately.
CREATE TABLE cajeer_book.search_embedding (
    installation text NOT NULL,
    scope text NOT NULL,
    page_key text NOT NULL,
    model text NOT NULL CHECK (length(model) BETWEEN 1 AND 128),
    digest text NOT NULL CHECK (digest ~ '^[a-f0-9]{64}$'),
    vector jsonb NOT NULL CHECK (jsonb_typeof(vector)='array'),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(installation,scope,page_key),
    FOREIGN KEY(installation,scope,page_key)
        REFERENCES cajeer_book.search_document(installation,scope,page_key) ON DELETE CASCADE
);
