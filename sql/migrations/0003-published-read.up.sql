-- PostgreSQL JSON extraction rejects escaped U+0000 even in an unrelated field.
-- The indexed routing metadata has a restricted ASCII grammar. Replace the escape
-- only in this derived lookup view, never in the stored JSON or returned snapshot.
CREATE FUNCTION cajeer_book.reader_json(value json) RETURNS json
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE SET search_path = pg_catalog
AS $$ SELECT replace(value::text, chr(92)||'u0000', chr(92)||'u0001')::json $$;
CREATE INDEX pages_public_key_idx ON cajeer_book.pages USING btree
    (installation, scope, (coalesce(cajeer_book.reader_json(value)->>'published_key', record_key)))
    WHERE json_typeof(cajeer_book.reader_json(value)->'published')='object';
CREATE INDEX pages_public_id_idx ON cajeer_book.pages USING btree
    (installation, scope, (cajeer_book.reader_json(value)->>'id'))
    WHERE json_typeof(cajeer_book.reader_json(value)->'published')='object';
CREATE INDEX pages_public_media_idx ON cajeer_book.pages USING gin
    (((cajeer_book.reader_json(value)->'published'->'media')::jsonb) jsonb_path_ops)
    WHERE json_typeof(cajeer_book.reader_json(value)->'published')='object';
