-- Audit Explorer filters outcome before its stable time/event cursor.
-- Existing rows and all audit hashes remain untouched.
CREATE INDEX audit_event_outcome ON cajeer_book.audit_event
    (installation, outcome, at_epoch DESC, event_id DESC);
