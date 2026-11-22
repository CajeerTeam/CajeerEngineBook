-- Kept separate from the immutable 1.0.5 daily counters. One row per aggregated
-- search phrase or goal per day; no sessions, visitor identities or raw requests.
CREATE TABLE cajeer_book.analytics_dimensions_daily (
  installation text NOT NULL REFERENCES cajeer_book.content_installation(installation) ON DELETE CASCADE,
  scope text NOT NULL,
  day date NOT NULL,
  kind text NOT NULL CHECK (kind IN ('search_query','conversion')),
  dimension text NOT NULL CHECK (length(dimension) BETWEEN 2 AND 80),
  hits bigint NOT NULL DEFAULT 0 CHECK (hits >= 0),
  PRIMARY KEY (installation, scope, day, kind, dimension)
);
