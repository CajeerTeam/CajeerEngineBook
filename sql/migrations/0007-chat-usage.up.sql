-- Atomic, per-installation and per-workspace daily AI chat quotas.
CREATE TABLE cajeer_book.chat_usage (
    installation text NOT NULL,
    scope text NOT NULL,
    day date NOT NULL,
    principal text NOT NULL CHECK (principal='workspace' OR principal ~ '^actor:[a-f0-9]{64}$'),
    requests integer NOT NULL CHECK (requests BETWEEN 0 AND 1000000),
    tokens bigint NOT NULL CHECK (tokens BETWEEN 0 AND 1000000000),
    PRIMARY KEY (installation,scope,day,principal)
);
