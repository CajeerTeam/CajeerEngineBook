-- Append-only audit ledger, independently retained from the notification journal.
CREATE TABLE IF NOT EXISTS cajeer_book.audit_format (
    singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton), version integer NOT NULL CHECK(version=1)
);
INSERT INTO cajeer_book.audit_format VALUES(true,1) ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS cajeer_book.audit_installation (
    installation text PRIMARY KEY REFERENCES cajeer_book.content_installation ON DELETE CASCADE,
    started_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE IF NOT EXISTS cajeer_book.audit_stream (
    installation text NOT NULL REFERENCES cajeer_book.audit_installation ON DELETE CASCADE,
    stream text NOT NULL CHECK(stream IN ('identity','legacy') OR stream ~ '^[0-9a-f]{32}/[0-9a-f]{32}$'),
    head_seq bigint NOT NULL DEFAULT 0,
    head_hash text NOT NULL DEFAULT repeat('0',64) CHECK(head_hash ~ '^[0-9a-f]{64}$'),
    anchor_seq bigint NOT NULL DEFAULT 0 CHECK(anchor_seq>=0),
    anchor_hash text NOT NULL DEFAULT repeat('0',64) CHECK(anchor_hash ~ '^[0-9a-f]{64}$'),
    CHECK(head_seq>=anchor_seq), PRIMARY KEY(installation,stream)
);
CREATE TABLE IF NOT EXISTS cajeer_book.audit_event (
    cursor_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    installation text NOT NULL, stream text NOT NULL, seq bigint NOT NULL CHECK(seq>0),
    event_id text NOT NULL CHECK(event_id ~ '^[0-9a-f]{32}$'),
    at_epoch bigint NOT NULL CHECK(at_epoch>=0),
    action text NOT NULL CHECK(action ~ '^[a-z][a-z0-9_.-]{0,63}$'),
    outcome text NOT NULL CHECK(outcome IN ('success','denied','limited','failure')),
    actor_id text NOT NULL CHECK(actor_id='' OR actor_id ~ '^[0-9a-f]{32}$'),
    organization text NOT NULL CHECK(organization='' OR organization ~ '^[0-9a-f]{32}$'),
    workspace text NOT NULL CHECK(workspace='' OR workspace ~ '^[0-9a-f]{32}$'),
    payload text NOT NULL CHECK(octet_length(payload)<=4096),
    previous_hash text NOT NULL CHECK(previous_hash ~ '^[0-9a-f]{64}$'),
    event_hash text NOT NULL CHECK(event_hash ~ '^[0-9a-f]{64}$'),
    UNIQUE(installation,stream,seq), UNIQUE(installation,event_id),
    FOREIGN KEY(installation,stream) REFERENCES cajeer_book.audit_stream ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS audit_event_cursor ON cajeer_book.audit_event(installation,at_epoch DESC,event_id DESC);
CREATE INDEX IF NOT EXISTS audit_event_scope ON cajeer_book.audit_event(installation,organization,workspace,at_epoch DESC,event_id DESC);
CREATE INDEX IF NOT EXISTS audit_event_action ON cajeer_book.audit_event(installation,action,at_epoch DESC,event_id DESC);
CREATE INDEX IF NOT EXISTS audit_event_actor ON cajeer_book.audit_event(installation,actor_id,at_epoch DESC,event_id DESC);
CREATE INDEX IF NOT EXISTS audit_event_time ON cajeer_book.audit_event(installation,at_epoch);
-- Ordinary SQL UPDATE is never a supported ledger operation. Prefix deletion is allowed
-- only after the operator has advanced the archive anchor in the same transaction.
CREATE OR REPLACE FUNCTION cajeer_book.audit_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' THEN RAISE EXCEPTION 'Audit events are immutable'; END IF;
    IF EXISTS(SELECT 1 FROM cajeer_book.audit_stream WHERE installation=OLD.installation AND stream=OLD.stream AND anchor_seq<OLD.seq) THEN
        RAISE EXCEPTION 'Audit event is not archived';
    END IF;
    RETURN OLD;
END $$;
DROP TRIGGER IF EXISTS audit_immutable ON cajeer_book.audit_event;
CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON cajeer_book.audit_event FOR EACH ROW EXECUTE FUNCTION cajeer_book.audit_immutable();
